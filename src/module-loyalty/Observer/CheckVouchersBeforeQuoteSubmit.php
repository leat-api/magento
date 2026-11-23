<?php

declare(strict_types=1);

namespace Leat\Loyalty\Observer;

use Leat\Loyalty\Model\AppliedCouponsManager;
use Leat\Loyalty\Model\Connector;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * A voucher is only marked as used in Leat after the order is placed, so before that every applied voucher
 * is checked against the customer's collectable vouchers again. A voucher that was used in the meantime
 * (another cart or session) stops the order, and so does a failed check: fail closed.
 */
class CheckVouchersBeforeQuoteSubmit implements ObserverInterface
{
    public function __construct(
        protected AppliedCouponsManager $appliedCouponsManager,
        protected Connector $leatConnector
    ) {
    }

    /**
     * @param Observer $observer
     * @return void
     * @throws LocalizedException
     */
    public function execute(Observer $observer): void
    {
        $quote = $observer->getData('quote');
        if (!$quote) {
            return;
        }

        $applied = $this->appliedCouponsManager->getAllAppliedCoupons(true, $quote);
        if (empty($applied)) {
            return;
        }

        $logger = $this->leatConnector->getLogger('reward_error');
        try {
            $collectable = array_merge(
                ...array_values($this->appliedCouponsManager->getSortedCollectableRewardUUIDs($quote))
            );
        } catch (\Throwable $e) {
            $logger->log('Could not verify applied vouchers before order placement: ' . $e->getMessage());
            throw new LocalizedException(__('We could not verify your vouchers. Please try again.'));
        }

        foreach ($applied as $loyaltyTransactionUUID) {
            if (!in_array($loyaltyTransactionUUID, $collectable, true)) {
                $logger->log(sprintf(
                    'Voucher %s is no longer collectable, blocking order placement for quote %s',
                    $loyaltyTransactionUUID,
                    $quote->getId()
                ));
                throw new LocalizedException(
                    __('A voucher in your cart is no longer available. Please remove it and try again.')
                );
            }
        }
    }
}
