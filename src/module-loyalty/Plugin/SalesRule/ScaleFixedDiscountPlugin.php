<?php

declare(strict_types=1);

namespace Leat\Loyalty\Plugin\SalesRule;

use Leat\Loyalty\Model\Voucher\AppliedVoucherCounter;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\Rule\Action\Discount\DiscountInterface;

/**
 * Fixed amount rules (cart_fixed, by_fixed) are evaluated once per rule, so three applied vouchers of the same
 * reward would give one discount. Scale the amount by the number of applied vouchers for the duration of the calculation.
 * Percentage rules are left alone: three 10% vouchers do not become 30%.
 */
class ScaleFixedDiscountPlugin
{
    public function __construct(protected AppliedVoucherCounter $voucherCounter)
    {
    }

    /**
     * @param DiscountInterface $subject
     * @param callable $proceed
     * @param Rule $rule
     * @param \Magento\Quote\Model\Quote\Item\AbstractItem $item
     * @param float $qty
     * @return mixed
     */
    public function aroundCalculate(DiscountInterface $subject, callable $proceed, $rule, $item, $qty)
    {
        $count = $this->voucherCounter->count((int)$rule->getId(), $item->getQuote());
        if ($count === 1) {
            return $proceed($rule, $item, $qty);
        }

        $original = $rule->getData('discount_amount');
        $rule->setData('discount_amount', (float)$original * $count);
        try {
            return $proceed($rule, $item, $qty);
        } finally {
            $rule->setData('discount_amount', $original);
        }
    }
}
