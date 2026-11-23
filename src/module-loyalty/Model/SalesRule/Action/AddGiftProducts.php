<?php

declare(strict_types=1);

namespace Leat\Loyalty\Model\SalesRule\Action;

use Leat\Loyalty\Model\Connector;
use Leat\Loyalty\Model\SalesRule\GiftItemInfo;
use Leat\Loyalty\Model\SalesRule\GiftProductManager;
use Leat\Loyalty\Model\Voucher\AppliedVoucherCounter;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Model\Quote;
use Magento\SalesRule\Model\Rule\Action\Discount\AbstractDiscount;
use Magento\SalesRule\Model\Rule\Action\Discount\DataFactory;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\Validator;

class AddGiftProducts extends AbstractDiscount
{
    /**
     * @param Validator $validator
     * @param DataFactory $discountDataFactory
     * @param PriceCurrencyInterface $priceCurrency
     * @param GiftProductManager $giftProductManager
     * @param Connector $leatConnector
     * @param AppliedVoucherCounter $voucherCounter
     * @param GiftItemInfo $giftItemInfo
     */
    public function __construct(
        Validator $validator,
        DataFactory $discountDataFactory,
        PriceCurrencyInterface $priceCurrency,
        protected GiftProductManager $giftProductManager,
        protected Connector $leatConnector,
        protected AppliedVoucherCounter $voucherCounter,
        protected GiftItemInfo $giftItemInfo,
    ) {
        parent::__construct($validator, $discountDataFactory, $priceCurrency);
    }

    /**
     * {@inheritdoc}
     */
    public function calculate($rule, $item, $qty)
    {
        $logger = $this->leatConnector->getLogger('reward');
        $discountData = $this->discountFactory->create();

        $extensionAttributes = $item->getExtensionAttributes();
        $isGiftFromThisRule = $extensionAttributes
            && $extensionAttributes->getIsGift()
            && $extensionAttributes->getGiftRuleId() === (int)$rule->getId();

        if ($isGiftFromThisRule) {
            $originalPrice = $item->getOriginalPrice() ?: (float)$item->getProduct()->getFinalPrice();
            $customPrice = $item->getCustomPrice();
            $effectivePrice = ($customPrice !== null) ? (float)$customPrice : $originalPrice;
            $discountAmount = ($originalPrice - $effectivePrice) * $qty;

            $discountData->setAmount($discountAmount);
            $discountData->setBaseAmount($discountAmount);
            $discountData->setOriginalAmount($originalPrice * $qty);
            $discountData->setBaseOriginalAmount($originalPrice * $qty);
        } else {
            $discountData->setAmount(0);
            $discountData->setBaseAmount(0);
            $discountData->setOriginalAmount(0);
            $discountData->setBaseOriginalAmount(0);
        }

        $logger->debug(sprintf(
            'AddGiftProducts discount action calculated for rule ID %d, item %s (SKU: %s), qty: %f',
            $rule->getId(),
            $item->getId(),
            $item->getSku(),
            $qty
        ));

        // Add gift products based on the rule action
        $this->addGiftProducts($rule, $item->getQuote());

        return $discountData;
    }

    /**
     * Add gift products based on the rule
     *
     * @param Rule $rule
     * @param Quote $quote
     * @return void
     */
    public function addGiftProducts(Rule $rule, Quote $quote): void
    {
        $logger = $this->leatConnector->getLogger('reward');

        $ruleId = (int)$rule->getId();

        // Check if gifts have already been processed for this quote and rule
        // Store a unique identifier for this quote + rule combination
        $quoteId = $quote->getId();
        $processedKey = 'processed_gift_rule_' . $ruleId;

        // If we've already processed this rule for this quote during this request, skip
        if ($quote->hasData($processedKey) && $quote->getData($processedKey) === true) {
            $logger->debug(sprintf(
                'Rule %d has already been processed for quote %s in this request, skipping',
                $ruleId,
                $quoteId
            ));
            return;
        }

        // Mark as processed to prevent duplicate additions
        $quote->setData($processedKey, true);

        // Get the discount amount (percentage) from the rule
        $discountAmount = (float)$rule->getDiscountAmount() ?: 100.0;

        // Get discount quantity (max number of gift items allowed)
        // If discount_qty is empty or 0, default to 1
        $discountQty = (int)$rule->getDiscountQty() ?: 1;

        $logger->debug(sprintf(
            'Processing gift rule %d with discount_amount: %f, discount_qty: %d for quote %s',
            $ruleId,
            $discountAmount,
            $discountQty,
            $quoteId
        ));

        // Get the SKUs from the rule
        $giftSkus = $rule->getData('gift_skus');

        // If not found directly, try to get from extension attributes
        if (empty($giftSkus)) {
            $extensionAttributes = $rule->getExtensionAttributes();
            if ($extensionAttributes && $extensionAttributes->getGiftSkus()) {
                $giftSkus = $extensionAttributes->getGiftSkus();
            }
        }

        if (empty($giftSkus)) {
            return;
        }

        // Every applied voucher of the reward owns its gift lines. A rule without applied vouchers (not mapped to a
        // reward) keeps one set of gifts without voucher uuid.
        $voucherUuids = $this->voucherCounter->uuids($ruleId, $quote) ?: [null];

        // Gift lines of this rule per voucher. Lines of a voucher that is gone, and lines without voucher uuid
        // (created before per voucher lines), leave the quote and are re-added below when still due.
        $linesPerVoucher = [];
        foreach ($quote->getAllItems() as $quoteItem) {
            // GiftItemInfo, not the extension attributes alone: those are not always loaded on a reloaded quote, and a
            // gift line that goes unseen here gets added again and merged into a qty 2 line
            if ($this->giftItemInfo->getGiftRuleId($quoteItem) !== $ruleId) {
                continue;
            }

            $uuid = $this->giftProductManager->getVoucherUuid($quoteItem);
            if (!in_array($uuid, $voucherUuids, true)) {
                if ($quoteItem->getId()) {
                    $quote->removeItem($quoteItem->getId());
                }
                continue;
            }

            // One unit per gift line, also heals a line that was merged up earlier
            if ((float)$quoteItem->getQty() !== 1.0) {
                $quoteItem->setQty(1);
            }

            $linesPerVoucher[(string)$uuid] = ($linesPerVoucher[(string)$uuid] ?? 0) + 1;
        }

        foreach ($voucherUuids as $uuid) {
            $existingGiftCount = $linesPerVoucher[(string)$uuid] ?? 0;
            $logger->debug(sprintf(
                'Found %d existing gift items for rule %d, voucher %s in quote %s',
                $existingGiftCount,
                $ruleId,
                (string)$uuid,
                $quoteId
            ));

            // If this voucher already has the maximum number of gift items, don't add more
            if ($existingGiftCount >= $discountQty) {
                continue;
            }

            try {
                $this->giftProductManager->addGiftProductsToQuote(
                    $quote,
                    $ruleId,
                    $giftSkus,
                    $discountQty - $existingGiftCount,
                    $discountAmount,
                    $uuid
                );
            } catch (\Exception $e) {
                $logger->log(sprintf(
                    'Error adding gift products for rule %d to quote %s: %s',
                    $ruleId,
                    $quoteId,
                    $e->getMessage()
                ));
            }
        }
    }
}
