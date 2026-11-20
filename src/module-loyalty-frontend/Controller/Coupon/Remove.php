<?php
declare(strict_types=1);

namespace Leat\LoyaltyFrontend\Controller\Coupon;

use Leat\Loyalty\Model\AppliedCouponsManager;
use Leat\Loyalty\Model\Connector;
use Leat\LoyaltyFrontend\Block\Widget\YourCoupons;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Controller for removing vouchers/coupons
 */
class Remove implements HttpPostActionInterface
{
    /**
     * Logger purpose for the Leat connector
     */
    protected const string LOGGER_PURPOSE = 'coupon_remove';

    public function __construct(
        protected RequestInterface $request,
        protected JsonFactory $jsonFactory,
        protected Connector $leatConnector,
        protected AppliedCouponsManager $appliedCouponsManager,
        protected CheckoutSession $checkoutSession,
        protected YourCoupons $yourCoupons,
    ) {
    }

    /**
     * Remove a reward/coupon from the cart
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $resultJson = $this->jsonFactory->create();

        try {
            $rewardId = $this->request->getParam('reward_id');

            if (!$rewardId) {
                throw new LocalizedException(__('Missing reward ID parameter'));
            }

            // Gift rewards remove quote rows; the cart page needs a reload to render that
            $itemCountBefore = count($this->checkoutSession->getQuote()->getAllItems());
            $this->appliedCouponsManager->removeCoupon($rewardId);
            $itemCountAfter = count($this->checkoutSession->getQuote()->getAllItems());

            return $resultJson->setData([
                'success' => true,
                'message' => __('Voucher successfully removed from your cart.'),
                'reward_id' => $rewardId,
                'is_quote_items_changed' => $itemCountBefore !== $itemCountAfter,
                // Saves the client a follow-up request for the refreshed voucher list
                'coupons' => $this->getCoupons(),
            ]);
        } catch (LocalizedException $e) {
            $this->leatConnector->getLogger(self::LOGGER_PURPOSE)->log(
                'Error removing voucher: ' . $e->getMessage(),
            );

            return $resultJson->setHttpResponseCode(400)->setData([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        } catch (\Exception $e) {
            $this->leatConnector->getLogger(self::LOGGER_PURPOSE)->log(
                'Unexpected error removing voucher: ' . $e->getMessage(),
            );

            return $resultJson->setHttpResponseCode(500)->setData([
                'success' => false,
                'message' => __('An unexpected error occurred while removing the voucher.')
            ]);
        }
    }

    /**
     * Refreshed voucher list; a failure here must not fail the apply/remove itself
     *
     * @return array|null
     */
    protected function getCoupons(): ?array
    {
        try {
            return json_decode($this->yourCoupons->getCollectableRewardsJson(), true) ?: [];
        } catch (\Throwable $e) {
            return null;
        }
    }
}
