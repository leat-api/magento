<?php

declare(strict_types=1);

namespace Leat\LoyaltyAdminUI\Model\Config\Source;

use Leat\Loyalty\Model\Config;
use Leat\Loyalty\Model\ResourceModel\Loyalty\ContactResource;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;

class SubscriptionTypes implements OptionSourceInterface
{
    public function __construct(
        protected ContactResource $contactResource,
        protected StoreManagerInterface $storeManager,
        protected Config $config,
    ) {
    }

    /**
     * Active Leat subscription types, fetched via the first enabled store's connection.
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int) $store->getId();
            if (!$this->config->getIsEnabled($storeId)) {
                continue;
            }

            try {
                foreach ($this->contactResource->getSubscriptionTypes($storeId) as $type) {
                    if ($type->isActive()) {
                        $options[$type->getUuid()] = ['value' => $type->getUuid(), 'label' => $type->getName()];
                    }
                }
            } catch (LocalizedException $e) {
                // Never break the admin page.
                return [];
            }
            break;
        }

        return array_values($options);
    }
}
