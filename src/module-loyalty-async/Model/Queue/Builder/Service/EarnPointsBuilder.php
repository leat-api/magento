<?php

declare(strict_types=1);

namespace Leat\LoyaltyAsync\Model\Queue\Builder\Service;

use Leat\AsyncQueue\Api\Data\JobInterface;
use Leat\AsyncQueue\Model\Queue\Request\RequestTypePool;
use Leat\LoyaltyAsync\Model\Queue\Builder\LoyaltyJobBuilder;
use Leat\LoyaltyAsync\Model\Queue\Type\Contact\Credit\EarnReward;
use Leat\LoyaltyAsync\Model\Queue\Type\Contact\Credit\Transaction;
use Magento\Framework\Exception\LocalizedException;

class EarnPointsBuilder
{
    public function __construct(
        protected LoyaltyJobBuilder $jobBuilder,
        protected RequestTypePool $requestTypePool
    ) {
    }

    /**
     * Queue a fixed amount of points for a claimed earning.
     *
     * @param int $customerId
     * @param int $storeId
     * @param string $earningId
     * @param string $periodKey
     * @param int $points
     * @param string $title Shown as the transaction note in Leat
     * @return JobInterface|null null when an identical job is already pending (debounced)
     * @throws LocalizedException
     */
    public function addEarnReward(
        int $customerId,
        int $storeId,
        string $earningId,
        string $periodKey,
        int $points,
        string $title
    ): ?JobInterface {
        $request = $this->requestTypePool->getRequestType(EarnReward::getTypeCode())
            ->setData(Transaction::DATA_CREDITS_KEY, $points)
            ->setData(EarnReward::DATA_EARNING_ID_KEY, $earningId)
            ->setData(EarnReward::DATA_PERIOD_KEY_KEY, $periodKey)
            ->setData(Transaction::INTERNAL_TRANSACTION_HASH, EarnReward::hashFor($customerId, $earningId, $periodKey))
            ->setData(Transaction::DATA_TRANSACTION_NOTE_KEY, $title);

        return $this->jobBuilder
            ->newJob((string) $customerId)
            ->setStoreId($storeId)
            ->addRequest($request->getPayload(), $request::getTypeCode())
            ->create();
    }
}
