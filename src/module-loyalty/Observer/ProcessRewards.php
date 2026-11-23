<?php

declare(strict_types=1);

namespace Leat\Loyalty\Observer;

use Leat\Loyalty\Model\AppliedCouponsManager;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Sales\Api\OrderRepositoryInterface;

class ProcessRewards implements ObserverInterface
{
    public function __construct(
        protected AppliedCouponsManager $appliedCouponsManager,
        protected OrderRepositoryInterface $orderRepository
    ) {
    }

    public function execute(Observer $observer)
    {
        $event = $observer->getEvent();
        $quote = $event->getData('quote');
        $order = $event->getData('order');

        $appliedCount = 0;
        $skippedCount = 0;
        foreach ($this->appliedCouponsManager->getAllAppliedCoupons(false, $quote) as $rewardUuid => $uuids) {
            if ($this->appliedCouponsManager->hasEffect($quote, (string)$rewardUuid)) {
                $appliedCount += count($uuids);
            } else {
                $skippedCount += count($uuids);
            }
        }
        $response = $this->appliedCouponsManager->markCouponsAsCollected($quote);

        if ($skippedCount > 0) {
            $order->addCommentToStatusHistory(sprintf(
                'Leat: %d voucher(s) had no effect on this order and were not marked as used.',
                $skippedCount
            ));
        }

        // The order is placed already, so a voucher that could not be marked as used is flagged on the order
        $failedCount = $appliedCount - array_sum(array_map('count', $response));
        if ($failedCount > 0) {
            $order->addCommentToStatusHistory(sprintf(
                'Leat: %d voucher(s) could not be marked as used. Check them in Leat to prevent reuse.',
                $failedCount
            ));
        }

        if (!empty($response)) {
            $order->setData('leat_loyalty_applied_coupons', json_encode($response));
        }

        if (!empty($response) || $failedCount > 0 || $skippedCount > 0) {
            $this->orderRepository->save($order);
        }
    }
}
