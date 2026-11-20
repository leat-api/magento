<?php

declare(strict_types=1);

namespace Leat\LoyaltyAdminUI\Model\Config\Source;

use Leat\Loyalty\Model\EarnPoints\Icons;
use Magento\Framework\Data\OptionSourceInterface;

class EarnIcons implements OptionSourceInterface
{
    /**
     * Names that aren't just the icon name with a capital
     */
    private const array LABELS = [
        'x' => 'X',
        'tiktok' => 'TikTok',
        'youtube' => 'YouTube',
        'linkedin' => 'LinkedIn',
        'whatsapp' => 'WhatsApp',
    ];

    public function __construct(protected Icons $icons)
    {
    }

    /**
     * @return array
     */
    public function toOptionArray(): array
    {
        return array_map(
            fn (string $name) => [
                'value' => $name,
                'label' => __(self::LABELS[$name] ?? ucfirst(str_replace('-', ' ', $name)))
            ],
            $this->icons->getNames()
        );
    }
}
