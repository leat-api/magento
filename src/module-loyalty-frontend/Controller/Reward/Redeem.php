<?php

declare(strict_types=1);

namespace Leat\LoyaltyFrontend\Controller\Reward;

use Leat\Loyalty\Model\AppliedCouponsManager;
use Leat\Loyalty\Model\Config;
use Leat\Loyalty\Model\Connector;
use Leat\Loyalty\Model\ResourceModel\Loyalty\ContactResource;
use Leat\Loyalty\Model\ResourceModel\Loyalty\RewardResource;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Store\Model\StoreManagerInterface;

class Redeem implements HttpPostActionInterface
{
    protected const string LOGGER_PURPOSE = 'redeem_reward';

    protected const string REWARD_ID_PARAM = 'reward_id';

    protected const string REDEEM_SUCCESS_MESSAGE = 'Reward redeemed successfully.';
    protected const string REDEEM_ERROR_MESSAGE = 'An error occurred while redeeming the reward, please check again in a few minutes to see if the has successfully redeemed.';

    public function __construct(
        protected JsonFactory $resultJsonFactory,
        protected RequestInterface $request,
        protected Session $customerSession,
        protected StoreManagerInterface $storeManager,
        protected RewardResource $rewardResource,
        protected Connector $connector,
        protected ManagerInterface $messageManager,
        protected AppliedCouponsManager $appliedCouponsManager,
        protected Config $config,
        protected ContactResource $contactResource
    ) {
    }

    /**
     * Execute action
     *
     * @return Json
     */
    public function execute(): Json
    {
        $resultJson = $this->resultJsonFactory->create();
        if (!$this->customerSession->isLoggedIn()) {
            return $resultJson->setData([
                'success' => false,
                'message' => __('Please log in to redeem rewards.')
            ]);
        }

        $rewardId = $this->request->getParam(self::REWARD_ID_PARAM);
        if (!$rewardId) {
            return $resultJson->setData([
                'success' => false,
                'message' => __('No reward ID provided.')
            ]);
        }

        $success = false;
        $applied = false;
        $customerId = (int)$this->customerSession->getCustomerId();
        $storeId = null;
        try {
            $storeId = (int)$this->storeManager->getStore()->getId();

            // Redeeming costs points, so never redeem a voucher that has no cart price rule to give anything
            if (!$this->canBeUsedInCart($customerId, (string)$rewardId, $storeId)) {
                $message = (string)__('This reward cannot be redeemed at the moment, no points have been spent.');
                $this->connector->getLogger(self::LOGGER_PURPOSE)->log(
                    sprintf('Reward %s has no cart price rule, redeem refused', $rewardId)
                );

                return $resultJson->setData(['success' => false, 'message' => $message, 'applied' => false]);
            }

            // Use RewardResource to directly redeem the reward
            $result = $this->rewardResource->createRewardReception($customerId, $rewardId, $storeId);

            // If we get here, the redemption was successful
            $success = true;
            $message = (string)__(self::REDEEM_SUCCESS_MESSAGE);

            // Digital rewards never become collectable vouchers, so there is nothing to apply to the cart
            if ($result instanceof \Piggy\Api\Models\Loyalty\Receptions\PhysicalRewardReception
                && $this->config->isAutoApplyOnRedeem($storeId)
            ) {
                // The reward is redeemed either way; a failed apply only adds to the message
                try {
                    // Pass the reward UUID: the new reception may not be in the cached collectable rewards yet
                    $combination = $this->appliedCouponsManager->addCoupon(
                        (string)$result->getUuid(),
                        (string)$rewardId
                    );
                    $applied = true;
                    $message = (string)__('Reward redeemed and applied to your cart.');
                    // Vouchers pushed out of the cart by this one (non-combinable priority)
                    foreach ($combination->rejected as $rejectedMessage) {
                        $message .= ' ' . $rejectedMessage;
                    }
                } catch (\Throwable $e) {
                    $this->connector->getLogger(self::LOGGER_PURPOSE)->log(sprintf(
                        'Error applying redeemed reward %s to cart: %s',
                        $rewardId,
                        $e->getMessage()
                    ));
                    $message = (string)__(self::REDEEM_SUCCESS_MESSAGE) . ' ' . $e->getMessage();
                }
            }
        } catch (\Throwable $e) {
            $this->connector->getLogger(self::LOGGER_PURPOSE)->log(sprintf(
                "Error redeeming reward: %s \n %s",
                $e->getMessage(),
                $e->getTraceAsString()
            ));
            $message = $this->hasInsufficientCredits($customerId, (string)$rewardId, $storeId)
                ? (string)__('You don\'t have enough points to redeem this reward.')
                : (string)__(self::REDEEM_ERROR_MESSAGE);
        } finally {
            if ($success) {
                $this->messageManager->addSuccessMessage($message);
            } else {
                $this->messageManager->addErrorMessage($message);
            }

            return $resultJson->setData([
                'success' => $success,
                'message' => $message,
                'applied' => $applied
            ]);
        }
    }

    /**
     * Physical rewards become vouchers and need a cart price rule linked to the reward. Digital rewards do not,
     * and an unknown reward or a failed lookup is left to the redeem call itself.
     *
     * @param int $customerId
     * @param string $rewardUuid
     * @param int $storeId
     * @return bool
     */
    protected function canBeUsedInCart(int $customerId, string $rewardUuid, int $storeId): bool
    {
        try {
            foreach ($this->rewardResource->getAvailableRewards($customerId, $storeId) as $reward) {
                if ($reward->getUuid() === $rewardUuid) {
                    return $reward->getRewardType() === 'DIGITAL'
                        || $this->appliedCouponsManager->hasSalesRule($rewardUuid);
                }
            }
        } catch (\Throwable $e) {
            // Fall through
        }

        return true;
    }

    /**
     * Only called after a failed redeem, to tell the customer why instead of the generic error
     *
     * @param int $customerId
     * @param string $rewardUuid
     * @param int|null $storeId
     * @return bool
     */
    protected function hasInsufficientCredits(int $customerId, string $rewardUuid, ?int $storeId): bool
    {
        try {
            $balance = (int)($this->contactResource->getCustomerContact($customerId)
                ->getCreditBalance()->getBalance() ?? 0);
            foreach ($this->rewardResource->getAvailableRewards($customerId, $storeId) as $reward) {
                if ($reward->getUuid() === $rewardUuid) {
                    return $balance < (int)$reward->getRequiredCredits();
                }
            }
        } catch (\Throwable $e) {
            // Fall back to the generic message
        }

        return false;
    }
}
