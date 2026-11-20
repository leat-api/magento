<?php

declare(strict_types=1);

namespace Leat\Loyalty\Model\Voucher;

use Leat\Loyalty\Model\SalesRule\ExtensionAttributesRepository;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\Quote;

/**
 * How many vouchers of a cart price rule's reward sit on a quote. A rule is evaluated once whatever that number is,
 * so the rule actions use it to give every applied voucher its own gift or discount.
 */
class AppliedVoucherCounter
{
    public function __construct(
        protected ExtensionAttributesRepository $ruleExtensionRepository,
        protected Json $json
    ) {
    }

    /**
     * @param int $ruleId
     * @param Quote $quote
     * @return int At least 1, also for rules that are not mapped to a Leat reward
     */
    public function count(int $ruleId, Quote $quote): int
    {
        return max(1, count($this->uuids($ruleId, $quote)));
    }

    /**
     * @param int $ruleId
     * @param Quote $quote
     * @return string[] Loyalty transaction UUIDs of the vouchers of this rule's reward on the quote
     */
    public function uuids(int $ruleId, Quote $quote): array
    {
        $rewardUuid = $this->ruleExtensionRepository->getByRuleId($ruleId)->getRewardUuid();
        $applied = (string)$quote->getData('leat_loyalty_applied_coupons');
        if (!$rewardUuid || $applied === '') {
            return [];
        }

        try {
            $applied = $this->json->unserialize($applied);
        } catch (\Exception $e) {
            return [];
        }

        return array_values($applied[$rewardUuid] ?? []);
    }
}
