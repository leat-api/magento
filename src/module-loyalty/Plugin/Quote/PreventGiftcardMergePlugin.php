<?php

declare(strict_types=1);

namespace Leat\Loyalty\Plugin\Quote;

use Leat\Loyalty\Model\ResourceModel\Loyalty\GiftcardResource;
use Leat\Loyalty\Model\SalesRule\GiftItemInfo;
use Leat\Loyalty\Model\SalesRule\GiftProductManager;
use Magento\Quote\Model\Quote\Item;
use Magento\Catalog\Model\Product;

/**
 * Plugin to prevent merging of gift card items, and of voucher gift lines, in the cart
 */
class PreventGiftcardMergePlugin
{
    public function __construct(protected GiftItemInfo $giftItemInfo)
    {
    }

    /**
     * Prevent gift cards from merging by returning false from representProduct
     * This is called by Magento\Quote\Model\Quote::getItemByProduct when checking for duplicate items
     *
     * @param Item $subject
     * @param bool $result
     * @param Product $product
     * @return bool
     */
    public function afterRepresentProduct(
        Item $subject,
        bool $result,
        Product $product
    ): bool {
        if (!$result) {
            return $result;
        }

        // A voucher gift line only represents the gift of the same rule, and a normal add never lands on it
        if ($this->getGiftRuleId($subject) !== $this->getProductGiftRuleId($product)
            || ($this->getBuyRequest($subject)[GiftProductManager::BUYREQUEST_VOUCHER_UUID] ?? null)
                !== ($this->getProductBuyRequest($product)[GiftProductManager::BUYREQUEST_VOUCHER_UUID] ?? null)
        ) {
            return false;
        }

        // If this isn't a gift card, respect the original result
        if (!$this->isGiftcard($subject)) {
            return $result;
        }

        // For gift cards, we need to compare buy requests to ensure they're truly identical
        $newBuyRequest = $product->getCustomOption('info_buyRequest');
        $itemBuyRequest = $this->getBuyRequest($subject);

        if ($newBuyRequest) {
            $newBuyRequestValue = is_array($newBuyRequest->getValue())
                ? $newBuyRequest->getValue()
                : json_decode($newBuyRequest->getValue(), true);

            foreach ([$newBuyRequestValue, $itemBuyRequest] as &$buyRequest) {
                unset($buyRequest['uenc']);
                unset($buyRequest['qty']);
            }

            $newBuyRequestHash = md5(serialize($newBuyRequestValue));
            $itemBuyRequestHash = md5(serialize($itemBuyRequest));

            $result = $newBuyRequestHash === $itemBuyRequestHash;
        }

        return $result;
    }

    /**
     * Rule id of the voucher gift this line holds, from its buy request or its stored gift attributes
     *
     * @param Item $item
     * @return int|null
     */
    private function getGiftRuleId(Item $item): ?int
    {
        // GiftItemInfo also looks the stored gift attributes up when they are not loaded on the item, which is the
        // case for gift lines that predate the buy request marker
        $ruleId = $this->getBuyRequest($item)[GiftProductManager::BUYREQUEST_GIFT_RULE_ID]
            ?? $this->giftItemInfo->getGiftRuleId($item);

        return $ruleId === null ? null : (int)$ruleId;
    }

    /**
     * Rule id of the voucher gift that is being added, from the buy request of the product
     *
     * @param Product $product
     * @return int|null
     */
    private function getProductGiftRuleId(Product $product): ?int
    {
        $ruleId = $this->getProductBuyRequest($product)[GiftProductManager::BUYREQUEST_GIFT_RULE_ID] ?? null;

        return $ruleId === null ? null : (int)$ruleId;
    }

    /**
     * Buy request of the product that is being added
     *
     * @param Product $product
     * @return array
     */
    private function getProductBuyRequest(Product $product): array
    {
        $value = $product->getCustomOption('info_buyRequest')?->getValue();

        return is_array($value) ? $value : (json_decode((string)$value, true) ?: []);
    }

    /**
     * Check if the item is a gift card
     *
     * @param Item $item
     * @return bool
     */
    private function isGiftcard(Item $item): bool
    {
        $buyRequest = $this->getBuyRequest($item);

        return isset($buyRequest[GiftcardResource::BUYREQUEST_OPTION_RECIPIENT_EMAIL]);
    }

    /**
     * Get buy request data from item
     *
     * @param Item $item
     * @return array
     */
    private function getBuyRequest(Item $item): array
    {
        $option = $item->getOptionByCode('info_buyRequest');
        if ($option) {
            if (is_array($option->getValue())) {
                return $option->getValue();
            } else {
                $decoded = json_decode($option->getValue(), true);
                return $decoded ? $decoded : [];
            }
        }

        return [];
    }
}
