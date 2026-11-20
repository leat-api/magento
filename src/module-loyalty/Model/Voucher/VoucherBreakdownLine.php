<?php

declare(strict_types=1);

namespace Leat\Loyalty\Model\Voucher;

use Leat\Loyalty\Api\Data\VoucherBreakdownLineInterface;
use Magento\Framework\Api\AbstractSimpleObject;

class VoucherBreakdownLine extends AbstractSimpleObject implements VoucherBreakdownLineInterface
{
    public function getRuleId(): int
    {
        return (int)$this->_get(self::RULE_ID);
    }

    public function setRuleId(int $ruleId): self
    {
        return $this->setData(self::RULE_ID, $ruleId);
    }

    public function getLabel(): string
    {
        return (string)$this->_get(self::LABEL);
    }

    public function setLabel(string $label): self
    {
        return $this->setData(self::LABEL, $label);
    }

    public function getAmount(): float
    {
        return (float)$this->_get(self::AMOUNT);
    }

    public function setAmount(float $amount): self
    {
        return $this->setData(self::AMOUNT, $amount);
    }

    public function getLoyaltyTransactionUuid(): string
    {
        return (string)$this->_get(self::LOYALTY_TRANSACTION_UUID);
    }

    public function setLoyaltyTransactionUuid(string $uuid): self
    {
        return $this->setData(self::LOYALTY_TRANSACTION_UUID, $uuid);
    }
}
