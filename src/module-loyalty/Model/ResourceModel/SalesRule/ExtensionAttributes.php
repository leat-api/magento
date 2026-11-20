<?php

declare(strict_types=1);

namespace Leat\Loyalty\Model\ResourceModel\SalesRule;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class ExtensionAttributes extends AbstractDb
{
    /**
     * @inheritdoc
     */
    protected function _construct()
    {
        $this->_init('leat_loyalty_salesrule_extension', 'entity_id');
    }

    /**
     * Get extension attributes by rule ID
     *
     * @param int $ruleId
     * @return array|false
     */
    public function getByRuleId(int $ruleId)
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable())
            ->where('rule_id = ?', $ruleId);

        return $connection->fetchRow($select);
    }

    /**
     * Get sales rule ID by reward UUID
     *
     * @param string $rewardUuid
     * @return int|null
     */
    public function getSalesRuleIdByRewardUuid(string $rewardUuid): ?int
    {
        if ($rewardUuid === '') {
            return null; // LIKE '%%' would match any rule
        }

        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable(), ['rule_id'])
            ->where('reward_uuid LIKE ?', "%{$rewardUuid}%");

        $ruleId = $connection->fetchOne($select);
        return $ruleId ? (int)$ruleId : null;
    }

    /**
     * Get combinability meta of the sales rules mapped to the given reward UUIDs
     *
     * Rewards without a mapped active rule are left out of the result. "name" is the rule's store label (shown to
     * customers), falling back to the rule name.
     *
     * @param string[] $rewardUuids
     * @param int $storeId
     * @return array<string, array{combinable: bool, max_applied: int, sort_order: int, name: string}>
     */
    public function getRuleMetaByRewardUuids(array $rewardUuids, int $storeId = 0): array
    {
        $rewardUuids = array_values(array_filter($rewardUuids));
        if (!$rewardUuids) {
            return [];
        }

        $connection = $this->getConnection();
        // reward_uuid can hold a comma separated list, hence LIKE (same as getSalesRuleIdByRewardUuid)
        $likes = array_map(
            static fn($uuid) => $connection->quoteInto('e.reward_uuid LIKE ?', "%{$uuid}%"),
            $rewardUuids
        );
        $select = $connection->select()
            ->from(['e' => $this->getMainTable()], ['reward_uuid', 'leat_combinable', 'leat_max_applied'])
            ->join(['r' => $this->getTable('salesrule')], 'r.rule_id = e.rule_id', ['rule_id', 'sort_order', 'name'])
            ->where('r.is_active = 1')
            ->where(implode(' OR ', $likes))
            ->order('e.rule_id ASC');

        $rows = $connection->fetchAll($select);
        $labels = $this->getRuleLabels(array_column($rows, 'rule_id'), $storeId);
        $result = [];
        foreach ($rewardUuids as $uuid) {
            foreach ($rows as $row) {
                if (str_contains((string)$row['reward_uuid'], $uuid)) {
                    $result[$uuid] = [
                        'combinable' => (bool)$row['leat_combinable'],
                        'max_applied' => max(1, (int)$row['leat_max_applied']),
                        'sort_order' => (int)$row['sort_order'],
                        'name' => $labels[(int)$row['rule_id']] ?? (string)$row['name'],
                    ];
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Customer facing rule labels (Labels tab of the cart price rule), store specific before the default
     *
     * @param int[] $ruleIds
     * @param int $storeId
     * @return array<int, string> ruleId => label
     */
    public function getRuleLabels(array $ruleIds, int $storeId): array
    {
        if (!$ruleIds) {
            return [];
        }

        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getTable('salesrule_label'), ['rule_id', 'store_id', 'label'])
            ->where('rule_id IN (?)', array_map('intval', $ruleIds))
            ->where('store_id IN (?)', array_unique([0, $storeId]))
            ->where("label IS NOT NULL AND label != ''")
            ->order('store_id ASC');

        $labels = [];
        foreach ($connection->fetchAll($select) as $row) {
            $labels[(int)$row['rule_id']] = (string)$row['label']; // store row (higher id) overwrites the default
        }

        return $labels;
    }
}
