<?php

declare(strict_types=1);

namespace Leat\Loyalty\Plugin\Quote;

use Leat\Loyalty\Model\AppliedCouponsManager;
use Leat\Loyalty\Model\SalesRule\GiftItemInfo;
use Magento\Checkout\Model\Cart;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartItemRepositoryInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartItemInterface;

/**
 * A voucher gift cannot be edited, and its quantity is fixed. It can leave the cart: removing the gift line
 * unapplies its voucher, and emptying the cart unapplies every voucher. AppliedCouponsManager removes gifts on the
 * quote directly, so these guards on the customer facing entry points do not get in its way.
 */
class ProtectGiftItemsPlugin
{
    public function __construct(
        protected GiftItemInfo $giftItemInfo,
        protected AppliedCouponsManager $appliedCouponsManager,
        protected CartRepositoryInterface $cartRepository
    ) {
    }

    /**
     * @param Cart $subject
     * @param int|string $itemId
     * @return array
     * @throws LocalizedException
     */
    public function beforeRemoveItem(Cart $subject, $itemId): array
    {
        $quote = $subject->getQuote();
        $item = $quote->getItemById((int)$itemId);
        if ($item && $this->giftItemInfo->getGiftRuleId($item) !== null) {
            $this->appliedCouponsManager->removeCouponsOfGiftItem($quote, $item);
        }

        return [$itemId];
    }

    /**
     * Emptying the cart (checkout/cart/updatePost, update_cart_action=empty_cart) unapplies the vouchers too
     *
     * @param Cart $subject
     * @param Cart $result
     * @return Cart
     */
    public function afterTruncate(Cart $subject, $result)
    {
        $this->appliedCouponsManager->removeAllCoupons($subject->getQuote());
        return $result;
    }

    /**
     * @param Cart $subject
     * @param int|string $itemId
     * @param mixed $requestInfo
     * @param mixed $updatingParams
     * @return array
     * @throws LocalizedException
     */
    public function beforeUpdateItem(Cart $subject, $itemId, $requestInfo = null, $updatingParams = null): array
    {
        $this->assertNotGift($subject->getQuote()->getItemById((int)$itemId));
        return [$itemId, $requestInfo, $updatingParams];
    }

    /**
     * Quantity changes of gift items are ignored, the rest of the cart update goes through
     *
     * @param Cart $subject
     * @param array $data itemId => ['qty' => ...]
     * @return array
     */
    public function beforeUpdateItems(Cart $subject, $data): array
    {
        foreach (array_keys((array)$data) as $itemId) {
            $item = $subject->getQuote()->getItemById((int)$itemId);
            if ($item && $this->giftItemInfo->getGiftRuleId($item) !== null) {
                unset($data[$itemId]);
            }
        }

        return [$data];
    }

    /**
     * REST / GraphQL: removing a gift item unapplies its voucher, which removes the gift
     *
     * @param CartItemRepositoryInterface $subject
     * @param callable $proceed
     * @param int|string $cartId
     * @param int|string $itemId
     * @return bool
     */
    public function aroundDeleteById(CartItemRepositoryInterface $subject, callable $proceed, $cartId, $itemId)
    {
        $quote = $this->cartRepository->getActive((int)$cartId);
        $item = $quote->getItemById((int)$itemId);
        if (!$item || $this->giftItemInfo->getGiftRuleId($item) === null) {
            return $proceed($cartId, $itemId);
        }

        $this->appliedCouponsManager->removeCouponsOfGiftItem($quote, $item);
        return true;
    }

    /**
     * REST / GraphQL: changing a gift item
     *
     * Registered on the cart item repository, but Checkout\Model\Cart::save() hits this method name too.
     *
     * @param mixed $subject
     * @param CartItemInterface|null $cartItem
     * @return array|null
     * @throws LocalizedException
     */
    public function beforeSave($subject, $cartItem = null): ?array
    {
        if ($subject instanceof CartItemRepositoryInterface && $cartItem && $cartItem->getItemId()) {
            $this->assertNotGift($cartItem);
        }

        return null;
    }

    /**
     * @param CartItemInterface|null $item
     * @return void
     * @throws LocalizedException
     */
    private function assertNotGift(?CartItemInterface $item): void
    {
        $this->throwIfGift($item && $this->giftItemInfo->getGiftRuleId($item) !== null);
    }

    /**
     * @param bool $isGift
     * @return void
     * @throws LocalizedException
     */
    private function throwIfGift(bool $isGift): void
    {
        if ($isGift) {
            throw new LocalizedException(
                __('This item is a free gift from a voucher. Remove the voucher to remove the gift.')
            );
        }
    }
}
