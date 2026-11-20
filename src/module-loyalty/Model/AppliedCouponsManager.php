<?php

declare(strict_types=1);

namespace Leat\Loyalty\Model;

use Leat\Loyalty\Model\ResourceModel\Loyalty\RewardResource;
use Leat\Loyalty\Model\SalesRule\EmptyCartGiftManager;
use Leat\Loyalty\Model\SalesRule\GiftItemInfo;
use Leat\Loyalty\Model\SalesRule\GiftProductManager;
use Leat\Loyalty\Model\QuoteItem\ExtensionAttributesRepository as QuoteItemExtensionRepository;
use Leat\Loyalty\Model\SalesRule\ExtensionAttributesRepository as SalesRuleExtensionRepository;
use Leat\Loyalty\Model\SalesRule\LoyaltyTransactionRelatedGiftRemover;
use Leat\Loyalty\Model\Voucher\CombinationResult;
use Leat\Loyalty\Model\Voucher\VoucherCombinationResolver;
use Leat\Loyalty\Observer\Discount\ProcessDiscount;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Api\Data\CartItemInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\SalesRule\Api\RuleRepositoryInterface;
use Piggy\Api\Models\Loyalty\Receptions\DigitalRewardReception;
use Piggy\Api\Models\Loyalty\Receptions\PhysicalRewardReception;

/**
 * Manages applied Leat coupons for quotes
 * Multiple vouchers per quote, one loyalty transaction per reward
 */
class AppliedCouponsManager
{
    protected array $collectableRewardsCache = [];

    public function __construct(
        protected CustomerSession                      $customerSession,
        protected CheckoutSession                      $checkoutSession,
        protected CartRepositoryInterface              $cartRepository,
        protected Json                                 $json,
        protected Connector                            $connector,
        protected RewardResource                       $rewardResource,
        protected ManagerInterface                     $eventManager,
        protected LoyaltyTransactionRelatedGiftRemover $couponRelatedGiftRemover,
        protected EmptyCartGiftManager                 $emptyCartGiftManager,
        protected SalesRuleExtensionRepository         $salesRuleExtensionRepository,
        protected QuoteItemExtensionRepository         $quoteItemExtensionRepository,
        protected RuleRepositoryInterface              $ruleRepository,
        protected VoucherCombinationResolver           $combinationResolver,
        protected GiftProductManager                   $giftProductManager,
        protected GiftItemInfo                         $giftItemInfo,
    )
    {
    }

    /**
     * Add a coupon (loyalty transaction UUID) to the quote's applied coupons
     *
     * Vouchers stack according to their rules' "Combinable with other Leat vouchers" flag;
     * see VoucherCombinationResolver. Vouchers that lose a priority conflict are removed from the quote.
     *
     * @param string $loyaltyTransactionUUID
     * @param string|null $rewardUUID Skips the collectable rewards lookup when known
     * @return CombinationResult
     * @throws LocalizedException When the new voucher itself cannot be applied
     */
    public function addCoupon(string $loyaltyTransactionUUID, ?string $rewardUUID = null): CombinationResult
    {
        try {
            $quote = $this->getQuote();
            if (!$quote) {
                throw new LocalizedException(__('Active quote not found'));
            }

            $appliedCoupons = $this->getAppliedCoupons($quote);
            $rewardUUID ??= $this->getRewardUUIDForLoyaltyTransactionUUID($loyaltyTransactionUUID, $quote);
            if (!$rewardUUID) {
                throw new LocalizedException(__('This voucher could not be found.'));
            }

            if (in_array($loyaltyTransactionUUID, $appliedCoupons[$rewardUUID] ?? [], true)) {
                return new CombinationResult($appliedCoupons, []); // Already applied, consider it a success
            }

            if (!$this->hasSalesRule($rewardUUID)) {
                throw new LocalizedException(__('This voucher cannot be applied to your cart.'));
            }

            $ruleMeta = $this->salesRuleExtensionRepository->getRuleMetaByRewardUuids(
                array_unique([...array_keys($appliedCoupons), $rewardUUID]),
                (int)$quote->getStoreId()
            );
            $result = $this->combinationResolver->resolve(
                $appliedCoupons,
                $rewardUUID,
                $loyaltyTransactionUUID,
                $ruleMeta
            );

            if (isset($result->rejected[$loyaltyTransactionUUID])) {
                throw new LocalizedException(__('%1', $result->rejected[$loyaltyTransactionUUID]));
            }

            // Vouchers that lost a priority conflict leave the quote, together with their gifts. Events wait until
            // the new voucher is confirmed to have an effect: a rollback restores them, and must not leave
            // listeners with a "removed" and no matching "applied".
            $removedUUIDs = [];
            foreach ($appliedCoupons as $appliedRewardUUID => $loyaltyTransactionUUIDs) {
                if (isset($result->applied[$appliedRewardUUID])) {
                    continue;
                }

                $this->resetGift($quote, (string)$appliedRewardUUID);
                array_push($removedUUIDs, ...$loyaltyTransactionUUIDs);
            }

            // Older vouchers of the same reward pushed out by the new one
            array_push($removedUUIDs, ...array_diff($appliedCoupons[$rewardUUID] ?? [], $result->applied[$rewardUUID]));

            // Save to quote
            $this->saveAppliedCoupons($quote, $result->applied);

            // A voucher that does nothing must never stay on the cart: it would be marked as used at checkout
            if (!$this->hasEffect($quote, $rewardUUID)) {
                $this->resetGift($quote, $rewardUUID);
                $this->saveAppliedCoupons($quote, $appliedCoupons); // No events were fired, so none to undo

                throw new LocalizedException($appliedCoupons
                    ? __('This voucher has no effect on your order: it cannot be combined with your other voucher or your order does not meet its conditions.')
                    : __('This voucher has no effect on your order: your order does not meet its conditions.'));
            }

            foreach ($removedUUIDs as $removedUUID) {
                $this->eventManager->dispatch(
                    'leat_coupon_removed',
                    ['quote' => $quote, 'loyalty_transaction_uuid' => $removedUUID]
                );
            }
            $this->eventManager->dispatch(
                'leat_coupon_applied',
                ['quote' => $quote, 'loyalty_transaction_uuid' => $loyaltyTransactionUUID]
            );

            $this->rewardResource->getLogger()->log(
                sprintf('Added coupon %s to quote %s', $loyaltyTransactionUUID, $quote->getId())
            );

            return $result;
        } catch (LocalizedException $e) {
            $this->rewardResource->getLogger()->log(
                sprintf('Error adding coupon: %s', $e->getMessage()),
            );
            throw $e;
        } catch (\Exception $e) {
            $this->rewardResource->getLogger()->log(
                sprintf('Error adding coupon: %s', $e->getMessage()),
            );
            throw new LocalizedException(__('Could not apply coupon: %1', $e->getMessage()));
        }
    }

    /**
     * Whether a cart price rule is linked to the reward. Without one a voucher can never give anything.
     *
     * @param string $rewardUUID
     * @return bool
     */
    public function hasSalesRule(string $rewardUUID): bool
    {
        return $this->salesRuleExtensionRepository->getSalesRuleIdByRewardUuid($rewardUUID) !== null;
    }

    /**
     * Whether the reward's rule did something on the last collected totals of the quote: a discount, a gift item
     * or free shipping.
     *
     * @param Quote $quote
     * @param string $rewardUUID
     * @return bool
     */
    public function hasEffect(Quote $quote, string $rewardUUID): bool
    {
        $ruleId = $this->salesRuleExtensionRepository->getSalesRuleIdByRewardUuid($rewardUUID);
        if ($ruleId === null) {
            return false;
        }

        foreach ($quote->getAllItems() as $item) {
            if ($this->giftItemInfo->getGiftRuleId($item) === $ruleId) {
                return true;
            }
        }

        $amounts = $quote->getData(ProcessDiscount::FIELD_DISCOUNT_AMOUNTS);
        $amount = 0.0;
        foreach ($amounts ? (array)$this->json->unserialize($amounts) : [] as $amountsPerRule) {
            $amount += abs((float)($amountsPerRule[$ruleId] ?? 0));
        }
        if (round($amount, 2) > 0) {
            return true;
        }

        // Free shipping has no amount: the rule counts when it applied to the cart
        try {
            $appliedRuleIds = explode(',', (string)$quote->getShippingAddress()->getAppliedRuleIds());
            return $this->ruleRepository->getById($ruleId)->getSimpleFreeShipping()
                && in_array((string)$ruleId, $appliedRuleIds, true);
        } catch (NoSuchEntityException $e) {
            return false;
        }
    }

    /**
     * Get the current active quote
     *
     * @return Quote|null
     * @throws NoSuchEntityException
     */
    protected function getQuote(): ?Quote
    {
        try {
            if (!$this->customerSession->isLoggedIn()) {
                return null;
            }

            return $this->checkoutSession->getQuote();
        } catch (NoSuchEntityException $e) {
            $this->rewardResource->getLogger()->log(
                sprintf('Error getting quote: %s', $e->getMessage())
            );
            return null;
        }
    }

    /**
     * Get applied coupons (loyalty transaction UUIDs) from quote
     *
     * @param Quote $quote
     * @return array
     */
    protected function getAppliedCoupons(Quote $quote): array
    {
        $appliedCouponsString = $quote->getData('leat_loyalty_applied_coupons');
        if (!$appliedCouponsString) {
            return [];
        }

        try {
            $appliedCoupons = $this->json->unserialize($appliedCouponsString);
            if (!is_array($appliedCoupons)) {
                return [];
            }

            return $appliedCoupons;
        } catch (\Exception $e) {
            $this->rewardResource->getLogger()->log(
                sprintf('Error unserializing applied coupons: %s', $e->getMessage())
            );
            return [];
        }
    }

    /**
     * @param $loyaltyTransactionUUID
     * @param Quote|null $quote
     * @return string|null
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function getRewardUUIDForLoyaltyTransactionUUID($loyaltyTransactionUUID, ?Quote $quote = null): ?string
    {
        $collectableRewardUUIDs = $this->getSortedCollectableRewardUUIDs($quote ?? $this->getQuote());
        foreach ($collectableRewardUUIDs as $rewardUUID => $loyaltyTransactionUUIDs) {
            if (in_array($loyaltyTransactionUUID, $loyaltyTransactionUUIDs)) {
                return $rewardUUID;
            }
        }

        return null;
    }

    /**
     * Returns an array of collectable reward UUIDs for the current customer
     *
     * @param Quote|null $quote
     * @param bool $hasBeenCollectedStatus
     * @return array
     * @throws LocalizedException
     */
    public function getSortedCollectableRewardUUIDs(?Quote $quote = null, bool $hasBeenCollectedStatus = false): array
    {
        $quote = $quote ?? $this->getQuote();
        if (!$quote->getCustomerId()) {
            return [];
        }

        $customerId = $quote->getCustomerId();
        if (!isset($this->collectableRewardsCache[$customerId])) {
            /** @var DigitalRewardReception|PhysicalRewardReception[] $collectableRewards */
            $collectableRewards = $this->rewardResource->getCollectableRewards($quote->getCustomerId());
            $result = [];
            foreach ($collectableRewards as $collectableReward) {
                if ($collectableReward->hasBeenCollected() !== $hasBeenCollectedStatus) {
                    continue;
                }

                $result[$collectableReward->getReward()->getUuid()][] = $collectableReward->getUuid();
            }

            $this->collectableRewardsCache[$customerId] = $result;
        }


        return $this->collectableRewardsCache[$customerId];
    }

    /**
     * Save applied coupons to quote
     *
     * @param Quote $quote
     * @param array $appliedCoupons
     * @throws \Exception
     */
    protected function saveAppliedCoupons(Quote $quote, array $appliedCoupons): void
    {
        try {
            $appliedCouponsString = $this->json->serialize($appliedCoupons);
            $quote->setData('leat_loyalty_applied_coupons', $appliedCouponsString);

            if (empty($quote->getItems())) {
                $this->emptyCartGiftManager->checkForApplicableGifts($quote);
            }

            // Recollect so rule discounts and gift items reflect the new voucher list in this same request
            $quote->setTotalsCollectedFlag(false)->collectTotals();
            $this->cartRepository->save($quote);
        } catch (\Exception $e) {
            $this->rewardResource->getLogger()->log(
                sprintf('Error saving applied coupons: %s', $e->getMessage()),
            );
            throw $e;
        }
    }

    /**
     * Remove a coupon (loyalty transaction UUID) from the quote's applied coupons (loyalty transaction UUIDs)
     *
     * @param string $loyaltyTransactionUUID
     * @param Quote|null $quote Defaults to the checkout session quote
     * @return bool
     * @throws LocalizedException
     */
    public function removeCoupon(string $loyaltyTransactionUUID, ?Quote $quote = null): bool
    {
        try {
            $quote ??= $this->getQuote();
            if (!$quote) {
                throw new LocalizedException(__('Active quote not found'));
            }

            $appliedCoupons = $this->getAppliedCoupons($quote);
            // Look in the quote itself: the voucher may no longer be in the collectable rewards
            $rewardUUID = null;
            foreach ($appliedCoupons as $appliedRewardUUID => $loyaltyTransactionUUIDs) {
                if (in_array($loyaltyTransactionUUID, $loyaltyTransactionUUIDs, true)) {
                    $rewardUUID = (string)$appliedRewardUUID;
                    break;
                }
            }
            if ($rewardUUID === null) {
                return true;
            }

            // Remove the coupon
            $appliedCoupons[$rewardUUID] = array_values(array_filter($appliedCoupons[$rewardUUID], function ($coupon) use ($loyaltyTransactionUUID) {
                return $coupon !== $loyaltyTransactionUUID;
            }));

            if (count($appliedCoupons[$rewardUUID]) === 0) {
                unset($appliedCoupons[$rewardUUID]);
                $this->resetGift($quote, $rewardUUID);
            }

            $this->eventManager->dispatch(
                'leat_coupon_removed',
                ['quote' => $quote, 'loyalty_transaction_uuid' => $loyaltyTransactionUUID]
            );

            // Save to quote
            $this->saveAppliedCoupons($quote, $appliedCoupons);

            $this->rewardResource->getLogger()->log(
                sprintf('Removed coupon %s from quote %s', $loyaltyTransactionUUID, $quote->getId())
            );

            return true;
        } catch (\Exception $e) {
            $this->rewardResource->getLogger()->log(
                sprintf('Error removing coupon: %s', $e->getMessage()),
            );
            throw new LocalizedException(__('Could not remove coupon: %1', $e->getMessage()));
        }
    }

    /**
     * Unapply every voucher of a quote, e.g. when the cart is emptied. The caller saves the quote.
     *
     * @param Quote $quote
     * @return void
     */
    public function removeAllCoupons(Quote $quote): void
    {
        foreach ($this->getAppliedCoupons($quote) as $loyaltyTransactionUUIDs) {
            foreach ($loyaltyTransactionUUIDs as $loyaltyTransactionUUID) {
                $this->eventManager->dispatch(
                    'leat_coupon_removed',
                    ['quote' => $quote, 'loyalty_transaction_uuid' => $loyaltyTransactionUUID]
                );
            }
        }

        $quote->setData('leat_loyalty_applied_coupons', $this->json->serialize([]));
    }

    /**
     * Unapply the voucher a gift line belongs to, which takes the gift with it. A gift line without voucher uuid
     * (created before per voucher lines) unapplies every voucher of its rule's reward.
     *
     * @param Quote $quote
     * @param CartItemInterface $item
     * @return void
     * @throws LocalizedException
     */
    public function removeCouponsOfGiftItem(Quote $quote, CartItemInterface $item): void
    {
        $uuid = $this->giftProductManager->getVoucherUuid($item);
        if ($uuid !== null) {
            $this->removeCoupon($uuid, $quote);
            return;
        }

        $ruleId = $this->giftItemInfo->getGiftRuleId($item);
        $rewardUuid = $ruleId !== null ? $this->salesRuleExtensionRepository->getByRuleId($ruleId)->getRewardUuid() : null;
        foreach ($this->getAppliedCoupons($quote)[$rewardUuid] ?? [] as $appliedUuid) {
            $this->removeCoupon($appliedUuid, $quote);
        }
    }

    /**
     * Remove the gift items that belong to the sales rule of the given reward
     *
     * @param Quote $quote
     * @param string $rewardUUID
     * @return void
     */
    protected function resetGift(Quote $quote, string $rewardUUID): void
    {
        $ruleId = $this->salesRuleExtensionRepository->getSalesRuleIdByRewardUuid($rewardUUID);
        if ($ruleId === null) {
            return;
        }

        $quote->setLoyaltyTransactionRewardRuleId($ruleId);
        $this->couponRelatedGiftRemover->execute($quote);

        $this->rewardResource->getLogger()->log(sprintf(
            'Reset gift in quote %s for rule %d',
            $quote->getId(),
            $ruleId
        ));
    }

    /**
     * Get all applied coupons (loyalty transaction UUIDs) for the current quote
     *
     * @param bool $flat
     * @return array
     */
    public function getAllAppliedCoupons(bool $flat = false, ?Quote $quote = null): array
    {
        try {
            $quote = $quote ?? $this->getQuote();
            if (!$quote) {
                return [];
            }

            if ($flat) {
                return call_user_func_array(
                    'array_merge',
                    array_values($this->getAppliedCoupons($quote))
                );
            }

            return $this->getAppliedCoupons($quote);
        } catch (\Exception $e) {
            $this->rewardResource->getLogger()->log(
                sprintf('Error getting all applied coupons: %s', $e->getMessage()),
            );
            return [];
        }
    }

    /**
     * Process the applied coupons as collected
     *
     * @param Quote $quote
     * @return void
     */
    public function markCouponsAsCollected(Quote $quote): array
    {
        $appliedCoupons = $this->getAppliedCoupons($quote);
        $result = [];
        foreach ($appliedCoupons as $rewardUUID => $loyaltyTransactions) {
            // Safeguard: a voucher without effect on the order must not be used up, the customer loses reward and points
            if (!$this->hasEffect($quote, (string)$rewardUUID)) {
                $this->rewardResource->getLogger('reward_error')->log(sprintf(
                    'Voucher(s) of reward %s had no effect on quote %s, not marking as used',
                    $rewardUUID,
                    $quote->getId()
                ));
                continue;
            }

            foreach ($loyaltyTransactions as $appliedCoupon) {
                try {
                    $response = $this->rewardResource->collectCollectableReward($appliedCoupon);
                    $this->rewardResource->getLogger()->log(sprintf(
                        'Marked coupon (Loyalty Transaction UUID: %s) as used, for customer with id: %s. Response UUID: %s',
                        $appliedCoupon,
                        (string)$quote->getCustomerId(),
                        $response->getUuid()
                    ));
                    $result[$rewardUUID][] = $response->getUuid();
                } catch (\Throwable $e) {
                    $this->rewardResource->getLogger('reward_error')->log(sprintf(
                        'Error marking coupon (Loyalty Transaction UUID: %s) as used: %s',
                        $appliedCoupon,
                        $e->getMessage()
                    ));
                }
            }
        }

        return $result;
    }
}
