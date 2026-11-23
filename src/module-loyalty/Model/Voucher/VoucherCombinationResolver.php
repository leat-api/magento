<?php

declare(strict_types=1);

namespace Leat\Loyalty\Model\Voucher;

/**
 * Decides which Leat vouchers may stay on a quote together.
 *
 * Any number of combinable vouchers plus at most one non-combinable voucher.
 * Between non-combinable vouchers the rule with the lowest sort_order wins; on a tie the voucher
 * that was already applied wins. At most the rule's max_applied (default 1) vouchers per reward; a newer one replaces the oldest. A reward without a mapped sales rule counts as combinable.
 */
class VoucherCombinationResolver
{
    /**
     * @param array<string, string[]> $applied rewardUuid => loyaltyTransactionUuids
     * @param string $newRewardUuid
     * @param string $newTxUuid
     * @param array<string, array{combinable: bool, max_applied: int, sort_order: int, name: string}> $ruleMeta
     * @return CombinationResult
     */
    public function resolve(
        array $applied,
        string $newRewardUuid,
        string $newTxUuid,
        array $ruleMeta
    ): CombinationResult {
        // The newest voucher of a reward always wins: the oldest ones beyond the maximum drop off
        $candidate = $applied;
        $candidate[$newRewardUuid] = array_slice(
            [...($applied[$newRewardUuid] ?? []), $newTxUuid],
            -max(1, $ruleMeta[$newRewardUuid]['max_applied'] ?? 1)
        );

        $nonCombinable = array_values(array_filter(
            array_keys($candidate),
            static fn($rewardUuid) => isset($ruleMeta[$rewardUuid]) && !$ruleMeta[$rewardUuid]['combinable']
        ));

        if (count($nonCombinable) <= 1) {
            return new CombinationResult($candidate, []);
        }

        // usort is stable and the new reward is last in $candidate, so on equal sort_order an applied voucher wins
        usort(
            $nonCombinable,
            static fn($a, $b) => $ruleMeta[$a]['sort_order'] <=> $ruleMeta[$b]['sort_order']
        );
        $winner = array_shift($nonCombinable);
        $message = (string)__(
            'Combining this voucher with others is not allowed. Voucher %1 has been prioritized.',
            $ruleMeta[$winner]['name']
        );

        $rejected = [];
        foreach ($nonCombinable as $rewardUuid) {
            foreach ($candidate[$rewardUuid] as $txUuid) {
                $rejected[$txUuid] = $message;
            }
            unset($candidate[$rewardUuid]);
        }

        return new CombinationResult($candidate, $rejected);
    }
}
