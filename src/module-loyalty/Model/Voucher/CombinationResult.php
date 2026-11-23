<?php

declare(strict_types=1);

namespace Leat\Loyalty\Model\Voucher;

/**
 * Outcome of combining a new Leat voucher with the vouchers already on the quote
 */
class CombinationResult
{
    /**
     * @param array<string, string[]> $applied rewardUuid => loyaltyTransactionUuids (same shape as the quote JSON)
     * @param array<string, string> $rejected loyaltyTransactionUuid => message
     */
    public function __construct(
        public readonly array $applied,
        public readonly array $rejected
    ) {
    }
}
