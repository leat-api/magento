<?php

declare(strict_types=1);

namespace Leat\LoyaltyAdminUI\Model\Config\Source;

use Leat\Loyalty\Model\Config;
use Leat\Loyalty\Model\ResourceModel\Loyalty\RewardResource;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class Reward implements OptionSourceInterface
{
    public function __construct(
        protected RewardResource $rewardResource,
        protected StoreManagerInterface $storeManager,
        protected Config $config,
    ) {
    }

    /**
     * Get options array
     *
     * @param int|null $excludeRuleId Option to exclude current rule ID from filtering
     * @return array
     */
    public function toOptionArray(): array
    {
        $options = [];

        try {
            /** @var \Loyalty\Api\Models\Loyalty\Rewards\Reward[] $allRewards */
            $allRewards = $this->rewardResource->getAllRewards();
            $shopNames = $this->getScopeLabels();

            foreach ($allRewards as $shopUuid => $rewards) {
                $shopName = $shopNames[(string) $shopUuid] ?? $shopUuid;

                foreach ($rewards as $reward) {
                    $options[] = [
                        'value' => $reward->getUUID(),
                        'label' => sprintf('%s (%s)', $reward->getTitle(), $shopName)
                    ];
                }
            }

            // Sort options alphabetically by label
            usort($options, function ($a, $b) {
                return $a['label'] <=> $b['label'];
            });
        } catch (LocalizedException $e) {
            // If there's an error getting rewards, return empty array
            // We don't want to break the admin UI
        }

        return $options;
    }

    /**
     * Map each shop UUID to the scope(s) it is configured in: Global, Website or Store View.
     *
     * @return array<string, string>
     */
    private function getScopeLabels(): array
    {
        $default = $this->config->getShopUuid(null, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
        $scopes = [];
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int) $store->getId();
            if (!$this->config->getIsEnabled($storeId)) {
                continue;
            }

            $uuid = $this->config->getShopUuid($storeId);
            $websiteId = (int) $store->getWebsiteId();
            if ($uuid === $default) {
                $scope = (string) __('Global scope');
            } elseif ($uuid === $this->config->getShopUuid($websiteId, ScopeInterface::SCOPE_WEBSITE)) {
                $scope = (string) __('Website: %1', $this->storeManager->getWebsite($websiteId)->getName());
            } else {
                $scope = (string) __('Store View: %1', $store->getName());
            }
            $scopes[(string) $uuid][$scope] = true;
        }

        return array_map(fn (array $s) => implode(', ', array_keys($s)), $scopes);
    }
}
