<?php

declare(strict_types=1);

namespace Leat\LoyaltyFrontend\Plugin\Cart;

use Leat\Loyalty\Model\SalesRule\GiftItemInfo;
use Magento\Checkout\Block\Cart\Item\Renderer\Actions;
use Magento\Checkout\CustomerData\ItemPool;
use Magento\Framework\Escaper;
use Magento\Quote\Api\Data\CartItemInterface;
use Magento\Quote\Model\Quote\Item;

/**
 * Voucher gifts get a "part of voucher" label instead of the edit action and the qty input (cart page and minicart)
 */
class GiftItemLabel
{
    public function __construct(
        protected GiftItemInfo $giftItemInfo,
        protected Escaper $escaper
    ) {
    }

    /**
     * Cart page: replace the edit/remove links
     *
     * @param Actions $subject
     * @param string $result
     * @return string
     */
    public function afterToHtml(Actions $subject, $result): string
    {
        $item = $subject->getItem();
        $label = $item ? $this->giftItemInfo->getVoucherLabel($item, (int)$item->getStoreId()) : null;
        if ($label === null) {
            return (string)$result;
        }

        // Edit stays hidden, remove stays: removing the gift unapplies its voucher
        $remove = '';
        foreach ($subject->getChildNames() as $childName) {
            if (str_ends_with($childName, '.remove')) {
                $remove .= $subject->getChildHtml($childName, false);
            }
        }

        // data-mage-init locks the qty input of this row
        return sprintf(
            '<span class="leat-gift-label" data-mage-init=\'{"Leat_LoyaltyFrontend/js/lock-gift-qty": {}}\'>%s</span>%s',
            $this->escaper->escapeHtml($this->text($item, $label)),
            $remove
        );
    }

    /**
     * Minicart: expose the label, the template hides qty and actions when it is set
     *
     * @param ItemPool $subject
     * @param array $result
     * @param Item $item
     * @return array
     */
    public function afterGetItemData(ItemPool $subject, $result, Item $item)
    {
        $label = $this->giftItemInfo->getVoucherLabel($item, (int)$item->getStoreId());
        if ($label !== null) {
            $result['leat_gift_label'] = $this->text($item, $label);
            $result['configure_url'] = null;
            $result['is_visible_in_site_visibility'] = false;
        }

        return $result;
    }

    /**
     * "Free" only when the gift is really free, partially discounted gifts get a neutral label
     *
     * @param CartItemInterface $item
     * @param string $label Customer facing voucher name
     * @return string
     */
    public function text(CartItemInterface $item, string $label): string
    {
        $free = (float)$item->getCustomPrice() <= 0;
        return (string)($free ? __('Free gift from voucher: %1', $label) : __('Gift from voucher: %1', $label));
    }
}
