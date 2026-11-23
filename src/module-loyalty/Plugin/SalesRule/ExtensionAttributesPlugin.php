<?php

declare(strict_types=1);

namespace Leat\Loyalty\Plugin\SalesRule;

use Leat\Loyalty\Model\SalesRule\ExtensionAttributesRepository;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\SalesRule\Api\Data\RuleInterface;
use Magento\SalesRule\Api\RuleRepositoryInterface;

/**
 * Exposes gift_skus, leat_combinable and leat_max_applied as extension attributes on the sales rule repository (REST, and modules
 * such as Amasty_Rgrid that duplicate rules through the repository).
 *
 * Works on extension attributes only: the repository passes Magento\SalesRule\Model\Data\Rule, which has no
 * getData(). The admin form saves through the Rule model, see Plugin\SalesRule\Model\RuleSavePlugin.
 */
class ExtensionAttributesPlugin
{
    public function __construct(
        private readonly ExtensionAttributesRepository $extensionRepository,
        private readonly ExtensionAttributesFactory $extensionFactory
    ) {
    }

    /**
     * @param RuleRepositoryInterface $subject
     * @param RuleInterface $rule
     * @return RuleInterface
     */
    public function afterGetById(RuleRepositoryInterface $subject, RuleInterface $rule): RuleInterface
    {
        $ruleId = (int)$rule->getRuleId();
        if (!$ruleId) {
            return $rule;
        }

        try {
            $ruleExtension = $this->extensionRepository->getByRuleId($ruleId);
            $extensionAttributes = $rule->getExtensionAttributes()
                ?? $this->extensionFactory->create(RuleInterface::class);
            $extensionAttributes->setGiftSkus($ruleExtension->getGiftSkus());
            $extensionAttributes->setLeatCombinable($ruleExtension->getLeatCombinable());
            $extensionAttributes->setLeatMaxApplied($ruleExtension->getLeatMaxApplied());
            $rule->setExtensionAttributes($extensionAttributes);
        } catch (\Exception $e) {
            // Don't break rule loading
        }

        return $rule;
    }

    /**
     * Persist the extension attributes given to the repository; values left out (null) are kept as stored
     *
     * @param RuleRepositoryInterface $subject
     * @param RuleInterface $result
     * @param RuleInterface $rule
     * @return RuleInterface
     */
    public function afterSave(
        RuleRepositoryInterface $subject,
        RuleInterface $result,
        RuleInterface $rule
    ): RuleInterface {
        $ruleId = (int)$result->getRuleId();
        $given = $rule->getExtensionAttributes();
        if (!$ruleId || !$given) {
            return $result;
        }

        try {
            $giftSkus = $given->getGiftSkus();
            $combinable = $given->getLeatCombinable();
            $maxApplied = $given->getLeatMaxApplied();
            if ($giftSkus === null && $combinable === null && $maxApplied === null) {
                return $result;
            }

            $ruleExtension = $this->extensionRepository->getByRuleId($ruleId);
            $ruleExtension->setRuleId($ruleId);
            if ($giftSkus !== null) {
                $ruleExtension->setGiftSkus($giftSkus);
            }
            if ($combinable !== null) {
                $ruleExtension->setLeatCombinable((bool)$combinable);
            }
            if ($maxApplied !== null) {
                $ruleExtension->setLeatMaxApplied((int)$maxApplied);
            }
            $this->extensionRepository->save($ruleExtension);

            $resultExtension = $result->getExtensionAttributes()
                ?? $this->extensionFactory->create(RuleInterface::class);
            $resultExtension->setGiftSkus($ruleExtension->getGiftSkus());
            $resultExtension->setLeatCombinable($ruleExtension->getLeatCombinable());
            $resultExtension->setLeatMaxApplied($ruleExtension->getLeatMaxApplied());
            $result->setExtensionAttributes($resultExtension);
        } catch (\Exception $e) {
            // Don't break rule saving
        }

        return $result;
    }
}
