<?php

declare(strict_types=1);

namespace Leat\LoyaltyFrontend\Plugin\Minicart;

use Magento\Checkout\CustomerData\Cart;
use Magento\Checkout\Model\Session;
use Magento\Framework\Pricing\PriceCurrencyInterface;

/**
 * The core minicart only shows the subtotal before discounts, so a voucher applied elsewhere (loyalty page) looks
 * like it did nothing until the cart page is opened. Expose the discount for the minicart subtotal template.
 */
class AddDiscount
{
    public function __construct(
        protected Session $checkoutSession,
        protected PriceCurrencyInterface $priceCurrency
    ) {
    }

    /**
     * @param Cart $subject
     * @param array $result
     * @return array
     */
    public function afterGetSectionData(Cart $subject, array $result): array
    {
        $quote = $this->checkoutSession->getQuote();
        $discount = round((float)$quote->getSubtotal() - (float)$quote->getSubtotalWithDiscount(), 2);
        $result['leat_discount'] = $discount > 0
            ? $this->priceCurrency->format($discount, false, PriceCurrencyInterface::DEFAULT_PRECISION, $quote->getStore())
            : null;

        return $result;
    }
}
