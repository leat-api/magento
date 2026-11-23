<?php

declare(strict_types=1);

namespace Leat\LoyaltyFrontend\Controller\Reward;

use Leat\LoyaltyFrontend\Block\Widget\YourCoupons;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;

class CollectibleRewards implements HttpGetActionInterface
{
    public function __construct(
        protected JsonFactory $resultJsonFactory,
        protected Session $customerSession,
        protected YourCoupons $yourCoupons,
    ) {
    }

    /**
     * Execute action
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $resultJson = $this->resultJsonFactory->create();
        if (!$this->customerSession->isLoggedIn()) {
            return $resultJson->setData([
                'success' => false,
                'message' => __('Customer is not logged in')
            ]);
        }

        $couponsArray = [];
        $success = false;
        try {
            // The block adds is_applied, same as the server rendered widget
            $couponsJson = $this->yourCoupons->getCollectableRewardsJson();
            $couponsArray = json_decode($couponsJson, true) ?: [];

            $success = true;
        } catch (\Throwable $e) {
            $this->yourCoupons->getLogger()->log($e->getMessage());
        } finally {
            return $resultJson->setData([
                'success' => $success,
                'coupons' => $couponsArray
            ]);
        }
    }
}
