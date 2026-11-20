<?php

declare(strict_types=1);

namespace Leat\Loyalty\Model\ResourceModel;

use Magento\Framework\DB\Adapter\DuplicateException;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Earn points claims. The unique key (customer_id, earning_id, period_key) is the lock:
 * whoever inserts the row first owns the claim.
 */
class EarnClaim extends AbstractDb
{
    public const TABLE_NAME = 'leat_loyalty_earning_claim';
    public const ID_FIELD_NAME = 'claim_id';

    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->_init(self::TABLE_NAME, self::ID_FIELD_NAME);
    }

    /**
     * Claim an earning for a period.
     *
     * @param int $customerId
     * @param string $earningId
     * @param string $periodKey
     * @param int $points
     * @return bool true when this call made the claim, false when it was already claimed
     */
    public function claim(int $customerId, string $earningId, string $periodKey, int $points): bool
    {
        try {
            $this->getConnection()->insert($this->getMainTable(), [
                'customer_id' => $customerId,
                'earning_id' => $earningId,
                'period_key' => $periodKey,
                'points' => $points,
            ]);
        } catch (DuplicateException) {
            return false;
        }

        return true;
    }

    /**
     * Every claim of a customer as "earning_id|period_key".
     *
     * @param int $customerId
     * @return string[]
     */
    public function getClaimedKeys(int $customerId): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable(), ['earning_id', 'period_key'])
            ->where('customer_id = ?', $customerId);

        return array_map(
            static fn (array $row) => $row['earning_id'] . '|' . $row['period_key'],
            $connection->fetchAll($select)
        );
    }
}
