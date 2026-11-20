<?php

declare(strict_types=1);

namespace Leat\Loyalty\Plugin\Quote;

use Leat\Loyalty\Observer\Discount\ProcessDiscount;
use Magento\Quote\Model\Quote;

/**
 * ProcessDiscount adds to the per rule discount arrays on every collect, so without a reset they keep amounts of
 * earlier collects and "did this voucher give a discount" cannot be answered.
 */
class ResetDiscountArraysPlugin
{
    /**
     * @param Quote $subject
     * @return null
     */
    public function beforeCollectTotals(Quote $subject)
    {
        // collectTotals returns early when the totals are collected already, the arrays must stay then
        if (!$subject->getTotalsCollectedFlag()) {
            $subject->setData(ProcessDiscount::FIELD_DESCRIPTIONS, null);
            $subject->setData(ProcessDiscount::FIELD_DISCOUNT_AMOUNTS, null);
            $subject->setData(ProcessDiscount::FIELD_BASE_AMOUNTS, null);
        }

        return null;
    }
}
