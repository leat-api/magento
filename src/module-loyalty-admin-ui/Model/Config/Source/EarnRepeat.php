<?php

declare(strict_types=1);

namespace Leat\LoyaltyAdminUI\Model\Config\Source;

use Leat\Loyalty\Model\EarnPoints\Repeat;
use Magento\Framework\Data\OptionSourceInterface;

class EarnRepeat implements OptionSourceInterface
{
    /**
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => Repeat::ONCE, 'label' => __('Once')],
            ['value' => Repeat::YEARLY, 'label' => __('Every year')],
            ['value' => Repeat::MONTHLY, 'label' => __('Every month')],
            ['value' => Repeat::WEEKLY, 'label' => __('Every week')],
            ['value' => Repeat::DAILY, 'label' => __('Every day')],
        ];
    }
}
