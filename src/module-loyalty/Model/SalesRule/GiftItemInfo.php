<?php

declare(strict_types=1);

namespace Leat\Loyalty\Model\SalesRule;

use Leat\Loyalty\Model\QuoteItem\ExtensionAttributesRepository as ItemExtensionRepository;
use Leat\Loyalty\Model\SalesRule\ExtensionAttributesRepository as RuleExtensionRepository;
use Magento\Quote\Api\Data\CartItemInterface;

/**
 * Tells whether a cart item is a free gift of a Leat voucher, and which voucher
 */
class GiftItemInfo
{
    public function __construct(
        protected ItemExtensionRepository $itemExtensionRepository,
        protected RuleExtensionRepository $ruleExtensionRepository
    ) {
    }

    /**
     * @param CartItemInterface $item
     * @return int|null Sales rule id when the item is a voucher gift
     */
    public function getGiftRuleId(CartItemInterface $item): ?int
    {
        $ext = $item->getExtensionAttributes();
        if ($ext && $ext->getIsGift()) {
            return $ext->getGiftRuleId() ? (int)$ext->getGiftRuleId() : null;
        }

        // Extension attributes are not always loaded on the item
        return $item->getItemId() ? $this->getGiftRuleIdByItemId((int)$item->getItemId()) : null;
    }

    /**
     * @param int $itemId
     * @return int|null
     */
    public function getGiftRuleIdByItemId(int $itemId): ?int
    {
        try {
            $attrs = $this->itemExtensionRepository->getByItemId($itemId);
            return $attrs->getIsGift() && $attrs->getGiftRuleId() ? (int)$attrs->getGiftRuleId() : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Customer facing name of the voucher the gift belongs to
     *
     * @param CartItemInterface $item
     * @param int $storeId
     * @return string|null Null when the item is not a gift
     */
    public function getVoucherLabel(CartItemInterface $item, int $storeId): ?string
    {
        $ruleId = $this->getGiftRuleId($item);
        if ($ruleId === null) {
            return null;
        }

        return $this->ruleExtensionRepository->getRuleLabels([$ruleId], $storeId)[$ruleId]
            ?? (string)__('Leat voucher');
    }
}
