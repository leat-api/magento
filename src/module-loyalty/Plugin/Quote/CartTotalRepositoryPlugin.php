<?php

declare(strict_types=1);

namespace Leat\Loyalty\Plugin\Quote;

use Leat\Loyalty\Api\Data\VoucherBreakdownLineInterfaceFactory;
use Leat\Loyalty\Model\AppliedCouponsManager;
use Leat\Loyalty\Model\SalesRule\ExtensionAttributesRepository as SalesRuleExtensionRepository;
use Leat\Loyalty\Observer\Discount\ProcessDiscount;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\CartTotalRepositoryInterface;
use Magento\Quote\Api\Data\TotalsExtensionFactory;
use Magento\Quote\Api\Data\TotalsInterface;
use Magento\Quote\Model\Quote;

/**
 * Adds leat_voucher_breakdown (discount per applied Leat voucher) to cart totals
 */
class CartTotalRepositoryPlugin
{
    public function __construct(
        protected CartRepositoryInterface $cartRepository,
        protected AppliedCouponsManager $appliedCouponsManager,
        protected SalesRuleExtensionRepository $salesRuleExtensionRepository,
        protected VoucherBreakdownLineInterfaceFactory $lineFactory,
        protected TotalsExtensionFactory $totalsExtensionFactory,
        protected Json $json
    ) {
    }

    /**
     * @param CartTotalRepositoryInterface $subject
     * @param TotalsInterface $totals
     * @param int|string $cartId
     * @return TotalsInterface
     */
    public function afterGet(CartTotalRepositoryInterface $subject, TotalsInterface $totals, $cartId): TotalsInterface
    {
        try {
            /** @var Quote $quote */
            $quote = $this->cartRepository->getActive((int)$cartId);
        } catch (\Exception $e) {
            return $totals;
        }

        $applied = $this->appliedCouponsManager->getAllAppliedCoupons(false, $quote);
        if (!$applied) {
            return $totals;
        }

        $amountsPerRate = $this->decode($quote->getData(ProcessDiscount::FIELD_DISCOUNT_AMOUNTS));
        $descriptions = $this->decode($quote->getData(ProcessDiscount::FIELD_DESCRIPTIONS));

        $ruleIds = [];
        foreach (array_keys($applied) as $rewardUuid) {
            $ruleIds[$rewardUuid] = $this->salesRuleExtensionRepository->getSalesRuleIdByRewardUuid((string)$rewardUuid);
        }
        // Store label, not the rule description/name: those are admin-only
        $labels = $this->salesRuleExtensionRepository->getRuleLabels(
            array_values(array_filter($ruleIds)),
            (int)$quote->getStoreId()
        );

        $lines = [];
        foreach ($applied as $rewardUuid => $loyaltyTransactionUuids) {
            $ruleId = $ruleIds[$rewardUuid];
            if ($ruleId === null) {
                continue;
            }

            // A gift's "discount" is informational: the gift item is priced at 0, so it is not part of the totals
            if (($descriptions[$ruleId][ProcessDiscount::SUB_FIELD_SIMPLE_ACTION] ?? null) === 'add_gift_products') {
                continue;
            }

            $amount = 0.0;
            foreach ($amountsPerRate as $amountsPerRule) {
                $amount += (float)($amountsPerRule[$ruleId] ?? 0);
            }
            $amount = round(abs($amount), 2);
            // No row for a voucher that gives nothing, e.g. blocked by "Discard subsequent rules"
            if ($amount <= 0) {
                continue;
            }

            $lines[] = $this->lineFactory->create()
                ->setRuleId($ruleId)
                ->setLabel($labels[$ruleId] ?? (string)__('Leat voucher'))
                ->setAmount($amount)
                ->setLoyaltyTransactionUuid((string)reset($loyaltyTransactionUuids));
        }

        if (!$lines) {
            return $totals;
        }

        $extension = $totals->getExtensionAttributes() ?? $this->totalsExtensionFactory->create();
        $extension->setLeatVoucherBreakdown($lines);
        $totals->setExtensionAttributes($extension);

        return $totals;
    }

    /**
     * @param mixed $value
     * @return array
     */
    private function decode($value): array
    {
        if (!$value) {
            return [];
        }

        try {
            $decoded = $this->json->unserialize($value);
            return is_array($decoded) ? $decoded : [];
        } catch (\InvalidArgumentException $e) {
            return [];
        }
    }
}
