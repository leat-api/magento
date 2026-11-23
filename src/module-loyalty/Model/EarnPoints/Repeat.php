<?php

declare(strict_types=1);

namespace Leat\Loyalty\Model\EarnPoints;

/**
 * How often an earning can be claimed, and the calendar bucket ("period key") a claim belongs to.
 */
class Repeat
{
    public const string ONCE = 'once';
    public const string YEARLY = 'yearly';
    public const string MONTHLY = 'monthly';
    public const string WEEKLY = 'weekly';
    public const string DAILY = 'daily';

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return [self::ONCE, self::YEARLY, self::MONTHLY, self::WEEKLY, self::DAILY];
    }

    /**
     * Period key for a repeat setting. $now must already be in the store timezone.
     * Unknown repeat values are treated as 'once'.
     *
     * @param string $repeat
     * @param \DateTimeInterface $now
     * @return string
     */
    public static function periodKey(string $repeat, \DateTimeInterface $now): string
    {
        return match ($repeat) {
            self::YEARLY => $now->format('Y'),
            self::MONTHLY => $now->format('Y-m'),
            self::WEEKLY => $now->format('o-\WW'),
            self::DAILY => $now->format('Y-m-d'),
            default => self::ONCE,
        };
    }
}
