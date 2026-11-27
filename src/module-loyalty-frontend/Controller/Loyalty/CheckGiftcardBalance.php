<?php

declare(strict_types=1);

namespace Leat\LoyaltyFrontend\Controller\Loyalty;

use Leat\Loyalty\Model\ResourceModel\Loyalty\GiftcardResource;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Json;
use Leat\Loyalty\Model\Config;
use Leat\Loyalty\Model\Connector;
use Leat\Loyalty\Model\Logger;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\Pricing\Helper\Data as PriceHelper;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\Exception\LocalizedException;
use Leat\Loyalty\Model\RequestRateLimiter;
use Magento\Customer\Model\Session as CustomerSession;

class CheckGiftcardBalance implements HttpPostActionInterface
{
    private const RATE_LIMIT_KEY = 'giftcard_balance_check';
    private const RATE_LIMIT_WINDOW = 86400; // checks per day, see Config::getMaxGiftcardBalanceChecks

    private ?Logger $leatLogger = null;

    /**
     * @param JsonFactory $resultJsonFactory
     * @param RequestInterface $request
     * @param GiftcardResource $giftcardResource
     * @param Config $config
     * @param Connector $leatConnector
     * @param DateTime $dateTime
     * @param PriceHelper $priceHelper
     * @param TimezoneInterface $localeDate
     * @param RequestRateLimiter $rateLimiter
     * @param CustomerSession $customerSession
     */
    public function __construct(
        private readonly JsonFactory $resultJsonFactory,
        private readonly RequestInterface $request,
        private readonly GiftcardResource $giftcardResource,
        private readonly Config $config,
        private readonly Connector $leatConnector,
        private readonly DateTime $dateTime,
        private readonly PriceHelper $priceHelper,
        private readonly TimezoneInterface $localeDate,
        private readonly RequestRateLimiter $rateLimiter,
        private readonly CustomerSession $customerSession
    ) {
         $this->leatLogger = $this->leatConnector->getLogger('leat_giftcard_balance_check');
    }

    /**
     * Execute action based on request and return result
     *
     * @return Json
     */
    public function execute(): Json
    {
        $resultJson = $this->resultJsonFactory->create();
        $response = ['success' => false, 'message' => __('An error occurred.')];

        // Use renamed config path check
        if (!$this->config->isGiftcardEnabled()) {
             $response['message'] = __('Gift card functionality is disabled.');
             return $resultJson->setData($response);
        }

        // Check rate limit per customer + IP: codes can be brute forced, a session cookie is easily thrown away
        if (!$this->rateLimiter->attempt(
            self::RATE_LIMIT_KEY,
            $this->config->getMaxGiftcardBalanceChecks(),
            self::RATE_LIMIT_WINDOW,
            (int)$this->customerSession->getCustomerId()
        )) {
            $response['message'] = __('Please try again later.');
            $this->leatLogger->log('[CheckGiftcardBalance] Rate limit exceeded.', false);
            return $resultJson->setData($response);
        }

        $content = $this->request->getContent();
        $giftcardCode = null;
        if ($content) {
            $params = json_decode($content, true);
            $giftcardCode = $params['giftcard_code'] ?? null;
        }

        if (empty($giftcardCode)) {
            $response['message'] = __('Please enter a gift card code.');
            return $resultJson->setData($response);
        }

        try {
            // --- Call Leat API to get gift card details ---
            $this->leatLogger->log('[CheckGiftcardBalance] Checking hash', true, ['code' => $giftcardCode]);
            $giftcardData = $this->giftcardResource->findGiftcardByHash($giftcardCode);

            if (empty($giftcardData->getUuid())) {
                 throw new LocalizedException(__('Gift card not found.'));
            }

            // --- Prepare Response Data ---
            $isActive = (bool)($giftcardData->isActive() ?? false);
            $availableAmountCents = (int)($giftcardData->getAmountInCents() ?? 0);
            $availableAmount = $availableAmountCents / 100.0;
            $expirationDate = $giftcardData->getExpirationDate();
            $formattedExpiration = null;

            $isExpired = false;
            if ($expirationDate) {
                if ($this->dateTime->gmtTimestamp($expirationDate) < $this->dateTime->gmtTimestamp()) {
                    $isExpired = true;
                    $isActive = false;
                }
                try {
                    $formattedExpiration = $this->localeDate->formatDateTime(
                        $expirationDate,
                        \IntlDateFormatter::MEDIUM,
                        \IntlDateFormatter::NONE,
                        null,
                        'UTC'
                    );
                } catch (\Exception $dateFormatError) {
                    $this->leatLogger->log('Could not format expiration date: ' . $dateFormatError->getMessage(), false);
                    $formattedExpiration = $expirationDate;
                }
            }

            $response['success'] = true;
            $response['message'] = __('Balance retrieved successfully.');
            $response['is_active'] = $isActive && !$isExpired;
            $response['amount_cents'] = $availableAmountCents;
            $response['amount'] = $availableAmount;
            $response['formatted_amount'] = $this->priceHelper->currency($availableAmount, true, false);
            $response['expiration_date'] = $formattedExpiration;
            $response['is_expired'] = $isExpired;

            $this->leatLogger->log('[CheckGiftcardBalance] Balance check successful.', true, ['code' => $giftcardCode, 'response' => $response]);
        } catch (LocalizedException $e) {
            $this->leatLogger->log('[CheckGiftcardBalance] Check failed: ' . $e->getMessage(), false, ['code' => $giftcardCode]);
            $response['message'] = $e->getMessage();
        } catch (\Exception $e) {
            $this->leatLogger->log('[CheckGiftcardBalance] Unexpected error: ' . $e->getMessage(), false, ['exception' => $e->getTraceAsString(), 'code' => $giftcardCode]);
            $response['message'] = __('An unexpected error occurred while checking the balance.');
        }

        return $resultJson->setData($response);
    }
}
