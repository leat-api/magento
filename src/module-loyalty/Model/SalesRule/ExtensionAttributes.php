<?php

declare(strict_types=1);

namespace Leat\Loyalty\Model\SalesRule;

use Magento\Framework\Model\AbstractModel;
use Leat\Loyalty\Model\ResourceModel\SalesRule\ExtensionAttributes as ResourceModel;

class ExtensionAttributes extends AbstractModel
{
    /**
     * @inheritdoc
     */
    protected function _construct()
    {
        $this->_init(ResourceModel::class);
    }

    /**
     * Get rule ID
     *
     * @return int|null
     */
    public function getRuleId(): ?int
    {
        return $this->getData('rule_id') ? (int)$this->getData('rule_id') : null;
    }

    /**
     * Set rule ID
     *
     * @param int $ruleId
     * @return $this
     */
    public function setRuleId(int $ruleId): self
    {
        return $this->setData('rule_id', $ruleId);
    }

    /**
     * Get reward UUID
     *
     * @return string|null
     */
    public function getRewardUuid(): ?string
    {
        return $this->getData('reward_uuid');
    }

    /**
     * Set reward UUID
     *
     * @param string|null $uuid
     * @return $this
     */
    public function setRewardUuid(?string $uuid): self
    {
        return $this->setData('reward_uuid', $uuid);
    }

    /**
     * Get gift SKUs
     *
     * @return string|null
     */
    public function getGiftSkus(): ?string
    {
        return $this->getData('gift_skus');
    }

    /**
     * Set gift SKUs
     *
     * @param string|null $skus
     * @return $this
     */
    public function setGiftSkus(?string $skus): self
    {
        return $this->setData('gift_skus', $skus);
    }

    /**
     * Is the rule combinable with other Leat vouchers
     *
     * @return bool
     */
    public function getLeatCombinable(): bool
    {
        return (bool)$this->getData('leat_combinable');
    }

    /**
     * Max vouchers of this reward on one cart (at least 1)
     *
     * @return int
     */
    public function getLeatMaxApplied(): int
    {
        return max(1, (int)$this->getData('leat_max_applied'));
    }

    /**
     * @param int $max
     * @return $this
     */
    public function setLeatMaxApplied(int $max): self
    {
        return $this->setData('leat_max_applied', max(1, $max));
    }

    /**
     * Set combinable with other Leat vouchers
     *
     * @param bool $combinable
     * @return $this
     */
    public function setLeatCombinable(bool $combinable): self
    {
        return $this->setData('leat_combinable', (int)$combinable);
    }
}
