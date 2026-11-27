<?php

declare(strict_types=1);

namespace Leat\AsyncQueue\Model;

use Leat\AsyncQueue\Api\Data\RequestInterface;
use Leat\AsyncQueue\Service\JobDigest;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Single definition of what "failing" and "exhausted" mean for a request.
 * - failing: has been attempted and not synced; it may still be retried.
 * - exhausted: the retry limit has been reached, only an admin can move it on.
 */
class RequestStatus implements OptionSourceInterface
{
    public const SYNCED = 'synced';
    public const PENDING = 'pending';
    public const FAILING = 'failing';
    public const EXHAUSTED = 'exhausted';

    /**
     * @param RequestInterface $request
     * @return string
     */
    public static function of(RequestInterface $request): string
    {
        return match (true) {
            $request->getIsSynced() => self::SYNCED,
            $request->getAttempt() >= JobDigest::MAX_ATTEMPTS => self::EXHAUSTED,
            $request->getAttempt() >= 1 => self::FAILING,
            default => self::PENDING,
        };
    }

    /**
     * SQL condition: the request has errored (attempted, not synced). Includes exhausted requests.
     *
     * @param string $alias
     * @return string
     */
    public static function failingSql(string $alias = ''): string
    {
        $p = $alias ? $alias . '.' : '';
        return sprintf('(%1$sis_synced = 0 AND %1$sattempt >= 1)', $p);
    }

    /**
     * SQL condition: the request ran out of retries.
     *
     * @param string $alias
     * @return string
     */
    public static function exhaustedSql(string $alias = ''): string
    {
        $p = $alias ? $alias . '.' : '';
        return sprintf('(%1$sis_synced = 0 AND %1$sattempt >= %2$d)', $p, JobDigest::MAX_ATTEMPTS);
    }

    /**
     * SQL expression resolving to one of the status codes.
     *
     * @return string
     */
    public static function sql(): string
    {
        return sprintf(
            "CASE WHEN is_synced = 1 THEN '%s' WHEN attempt >= %d THEN '%s' WHEN attempt >= 1 THEN '%s' ELSE '%s' END",
            self::SYNCED,
            JobDigest::MAX_ATTEMPTS,
            self::EXHAUSTED,
            self::FAILING,
            self::PENDING
        );
    }

    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::PENDING, 'label' => __('Pending')],
            ['value' => self::FAILING, 'label' => __('Failing (will retry)')],
            ['value' => self::EXHAUSTED, 'label' => __('Needs admin intervention')],
            ['value' => self::SYNCED, 'label' => __('Synced')],
        ];
    }
}
