<?php

declare(strict_types=1);

namespace Leat\LoyaltyAsync\Model\Queue\Type\Contact\Newsletter;

use Leat\LoyaltyAsync\Model\Queue\Type\ContactType;

class Subscribe extends ContactType
{
    protected const string TYPE_CODE = 'newsletter_subscribe';

    /**
     * Whether this type subscribes (true) or unsubscribes (false).
     */
    protected bool $subscribe = true;

    /**
     *  Set the configured subscription types (all when none are configured) to the wanted state.
     *  A missing contact is handled by ContactType::beforeExecute, never silently skipped.
     *
     * @return bool
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function execute(): bool
    {
        $storeId = (int) $this->getStoreId();
        $contactUuid = $this->contactResource->getContactUuid((int) $this->getJob()->getRelationId());
        $configured = $this->config->getNewsletterSubscriptionTypes($storeId);

        foreach ($this->contactResource->getSubscriptions($contactUuid, $storeId) as $subscription) {
            $typeUuid = $subscription->getSubscriptionType()->getUuid();
            if ($subscription->isSubscribed() === $this->subscribe
                || ($configured && !in_array($typeUuid, $configured, true))
            ) {
                continue;
            }

            $this->contactResource->setSubscribed($contactUuid, $typeUuid, $this->subscribe, $storeId);
        }

        return true;
    }
}
