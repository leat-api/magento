<?php

declare(strict_types=1);

namespace Leat\Loyalty\Api\Data;

/**
 * Discount given by one applied Leat voucher, exposed on cart totals
 */
interface VoucherBreakdownLineInterface
{
    public const RULE_ID = 'rule_id';
    public const LABEL = 'label';
    public const AMOUNT = 'amount';
    public const LOYALTY_TRANSACTION_UUID = 'loyalty_transaction_uuid';

    /**
     * @return int
     */
    public function getRuleId(): int;

    /**
     * @param int $ruleId
     * @return $this
     */
    public function setRuleId(int $ruleId): self;

    /**
     * @return string
     */
    public function getLabel(): string;

    /**
     * @param string $label
     * @return $this
     */
    public function setLabel(string $label): self;

    /**
     * Discount amount, positive
     *
     * @return float
     */
    public function getAmount(): float;

    /**
     * @param float $amount
     * @return $this
     */
    public function setAmount(float $amount): self;

    /**
     * @return string
     */
    public function getLoyaltyTransactionUuid(): string;

    /**
     * @param string $uuid
     * @return $this
     */
    public function setLoyaltyTransactionUuid(string $uuid): self;
}
