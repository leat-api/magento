<?php

declare(strict_types=1);

namespace Leat\LoyaltyAsync\Observer\Newsletter;

use Leat\LoyaltyAsync\Model\Queue\Type\Contact\Newsletter\Subscribe;
use Leat\LoyaltyAsync\Model\Queue\Type\Contact\Newsletter\Unsubscribe;
use Leat\LoyaltyAsync\Observer\ContactRequestObserver;
use Magento\Framework\Event\Observer;
use Magento\Newsletter\Model\Subscriber;

/**
 * Observer for newsletter_subscriber_save_commit_after and newsletter_subscriber_delete_commit_after.
 * - Queue a (un)subscribe for the customer's Leat contact.
 * - At registration this fires before the contact exists (Magento saves the subscriber before
 *   customer_register_success), so no contact UUID is required; the job handles a missing contact.
 */
class SubscriptionUpdate extends ContactRequestObserver
{
    // Unconfirmed (double opt-in pending) is deliberately absent: no job.
    protected const array STATUS_TYPE = [
        Subscriber::STATUS_SUBSCRIBED => Subscribe::class,
        Subscriber::STATUS_UNSUBSCRIBED => Unsubscribe::class,
        Subscriber::STATUS_NOT_ACTIVE => Unsubscribe::class,
    ];

    /**
     * @param Observer $observer
     * @param bool $requireUUID
     * @return bool
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function validateEvent(Observer $observer, bool $requireUUID = true): bool
    {
        return parent::validateEvent($observer, false);
    }

    /**
     * @param Observer $observer
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function addJob(Observer $observer): void
    {
        $subscriber = $observer->getSubscriber();
        $isDelete = str_contains($observer->getEvent()->getName(), 'delete');
        $type = $isDelete ? Unsubscribe::class : self::STATUS_TYPE[(int) $subscriber->getSubscriberStatus()] ?? null;
        if ($type === null) {
            return;
        }

        $this->jobBuilder->newJob((string) $subscriber->getCustomerId())
            ->setStoreId($this->getStoreId($observer))
            ->addRequest([], $type::getTypeCode())
            ->create();
    }

    /**
     * @param Observer $observer
     * @return int
     */
    public function getStoreId(Observer $observer): int
    {
        return (int) $observer->getSubscriber()->getStoreId();
    }

    /**
     * @param Observer $observer
     * @return int
     */
    public function getRelationId(Observer $observer): int
    {
        return (int) $observer->getSubscriber()->getCustomerId();
    }
}
