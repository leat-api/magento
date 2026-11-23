<?php

declare(strict_types=1);

namespace Leat\LoyaltyFrontend\Controller\EarnPoints;

use Leat\AsyncQueue\Model\Job;
use Leat\AsyncQueue\Service\JobDigest;
use Leat\Loyalty\Model\Config;
use Leat\Loyalty\Model\Connector;
use Leat\Loyalty\Model\EarnPoints\Repeat;
use Leat\Loyalty\Model\ResourceModel\EarnClaim;
use Leat\Loyalty\Model\ResourceModel\Loyalty\ContactResource;
use Leat\LoyaltyAsync\Model\Queue\Builder\Service\EarnPointsBuilder;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Claim the points of an earning. The claim row (unique per customer, earning and period) and the credit job are
 * committed together, so a second request can never queue the same claim again and a failure leaves nothing behind.
 */
class Claim implements HttpPostActionInterface
{
    protected const string LOGGER_PURPOSE = 'earn_points';
    protected const string EARNING_ID_PARAM = 'earning_id';

    public function __construct(
        protected JsonFactory $resultJsonFactory,
        protected RequestInterface $request,
        protected Session $customerSession,
        protected StoreManagerInterface $storeManager,
        protected Config $config,
        protected ContactResource $contactResource,
        protected EarnClaim $earnClaim,
        protected EarnPointsBuilder $earnPointsBuilder,
        protected TimezoneInterface $timezone,
        protected Connector $connector,
        protected JobDigest $jobDigest
    ) {
    }

    /**
     * @return Json
     */
    public function execute(): Json
    {
        $result = $this->resultJsonFactory->create();
        $storeId = (int) $this->storeManager->getStore()->getId();

        try {
            if (!$this->customerSession->isLoggedIn()) {
                return $this->respond($result, false, false, __('Please log in to earn points.'), $storeId);
            }

            $customerId = (int) $this->customerSession->getCustomerId();
            $earning = $this->config->getEarnPointsEarning(
                (string) $this->request->getParam(self::EARNING_ID_PARAM),
                $storeId
            );
            if (!$earning || $earning['points'] <= 0) {
                return $this->respond($result, false, false, __('This reward is not available.'), $storeId);
            }

            // Same conditions as the widget: Leat enabled, a contact, and a customer group that takes part
            if (!$this->config->getIsEnabled($storeId)
                || !$this->contactResource->hasContactUuid($customerId)
                || !in_array($this->customerSession->getCustomerGroupId(), $this->config->getCustomerGroupMapping())
            ) {
                return $this->respond($result, false, false, __('This reward is not available.'), $storeId);
            }

            $periodKey = Repeat::periodKey($earning['repeat'], $this->timezone->date());

            // Claim and job in one transaction: when queueing fails nothing is left behind, so the customer can retry.
            // The unique key still locks: a concurrent claim waits for the commit and then sees a duplicate.
            $connection = $this->earnClaim->getConnection();
            $connection->beginTransaction();
            try {
                if (!$this->earnClaim->claim($customerId, $earning['id'], $periodKey, $earning['points'])) {
                    $connection->rollBack();
                    return $this->respond($result, false, true, __('You have already earned these points.'), $storeId);
                }
                $job = $this->earnPointsBuilder->addEarnReward(
                    $customerId,
                    $storeId,
                    $earning['id'],
                    $periodKey,
                    $earning['points'],
                    $earning['title']
                );
                $connection->commit();
            } catch (\Throwable $e) {
                $connection->rollBack();
                throw $e;
            }
        } catch (\Throwable $e) {
            $this->log(sprintf("Error claiming earning: %s \n %s", $e->getMessage(), $e->getTraceAsString()));
            return $this->respond($result, false, false, __('Something went wrong, please try again later.'), $storeId);
        }

        // Credit right away so the balance and activity log are up to date when the response arrives.
        // A fixed credit doesn't depend on the customer's other jobs (the contact exists, checked above), so it must
        // not wait for an earlier job that is stuck. skip_validation only lives on this in-memory job: the cron still
        // applies the normal ordering to the queued job.
        // When this fails (Leat down, ...) the job stays queued and the cron picks it up.
        if ($job instanceof Job) {
            try {
                $job->setSkipValidation(true);
                $this->jobDigest->setJob($job)->execute();
            } catch (\Throwable $e) {
                $this->log(sprintf(
                    'Immediate credit of claim %d / %s failed: %s',
                    $customerId,
                    $earning['id'],
                    $e->getMessage()
                ));
            }
        }

        return $this->respond($result, true, true, __('Points added to your account.'), $storeId);
    }

    /**
     * @param Json $result
     * @param bool $success
     * @param bool $completed
     * @param \Magento\Framework\Phrase|string $message
     * @param int $storeId
     * @return Json
     */
    protected function respond(Json $result, bool $success, bool $completed, $message, int $storeId): Json
    {
        return $result->setData([
            'success' => $success,
            'completed' => $completed,
            'message' => (string) $message,
            'label' => $this->config->getEarnPointsCompletedLabel($storeId),
        ]);
    }

    /**
     * @param string $message
     * @return void
     */
    protected function log(string $message): void
    {
        $this->connector->getLogger(self::LOGGER_PURPOSE)->log($message);
    }
}
