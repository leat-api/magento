<?php

declare(strict_types=1);

namespace Leat\LoyaltyAsync\Model\Queue\Type\Contact\Credit;

/**
 * Credits a fixed amount of points for an earn points claim (an earning that is not an order).
 */
class EarnReward extends Transaction
{
    protected const string TYPE_CODE = 'earn_reward';

    public const string DATA_EARNING_ID_KEY = 'earning_id';
    public const string DATA_PERIOD_KEY_KEY = 'period_key';

    /**
     * Deterministic hash for a claim. The hash of Request::getTransactionHash() changes on every retry,
     * so it can't be used to prevent crediting the same claim twice.
     *
     * @param int $customerId
     * @param string $earningId
     * @param string $periodKey
     * @return string
     */
    public static function hashFor(int $customerId, string $earningId, string $periodKey): string
    {
        return md5(sprintf('%s|%d|%s|%s', self::TYPE_CODE, $customerId, $earningId, $periodKey));
    }

    /**
     * @inheritDoc
     */
    protected function execute(): mixed
    {
        $job = $this->getJob();
        $contactUuid = $this->contactResource->getContactUuid((int) $job->getRelationId());
        if (!$contactUuid) {
            // The claim is already committed: fail the job so it is retried instead of silently dropped
            throw new \RuntimeException(sprintf('No contact uuid for customer %d, cannot credit the claim', (int) $job->getRelationId()));
        }

        $transactionHash = (string) $this->getData(self::INTERNAL_TRANSACTION_HASH);
        if ($skipResult = $this->skipTransaction(self::INTERNAL_TRANSACTION_HASH, $transactionHash)) {
            $this->getLogger('skipped-transactions')->log(sprintf('Skipping transaction | %s ', $skipResult));
            $this->getRequest()->setLatestFailReason($skipResult);
            return null;
        }

        // Only the attributes Leat knows from AttributeResource::TRANSACTION_ATTRIBUTES; the hash identifies the claim.
        $reception = $this->getClient()->creditReceptions->create(
            $contactUuid,
            $this->config->getShopUuid(),
            null,
            (int) $this->getData(self::DATA_CREDITS_KEY),
            null,
            null,
            null,
            [
                self::INTERNAL_TRANSACTION_HASH => $transactionHash,
                self::INTERNAL_TRANSACTION_NOTE => $this->getData(self::DATA_TRANSACTION_NOTE_KEY),
            ]
        );

        // The type instance is shared by all jobs of a digest run: a duplicate job in the same run must skip too
        $this->cachedTransactionHashes[$contactUuid][] = $transactionHash;

        return $reception;
    }
}
