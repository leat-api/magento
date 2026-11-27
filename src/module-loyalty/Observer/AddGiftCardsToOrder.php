<?php

declare(strict_types=1);

namespace Leat\Loyalty\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Leat\Loyalty\Api\AppliedGiftCardRepositoryInterface;
use Leat\Loyalty\Model\Connector;
use Leat\Loyalty\Model\GiftCard\ApplicationService;

class AddGiftCardsToOrder implements ObserverInterface
{
    /**
     * @var AppliedGiftCardRepositoryInterface
     */
    private $appliedGiftCardRepository;

    /**
     * @var Connector
     */
    private $leatConnector;

    /**
     * Logs through the Leat logger (not Magento's) so every line passes the log filter.
     *
     * @param AppliedGiftCardRepositoryInterface $appliedGiftCardRepository
     * @param Connector $leatConnector
     */
    public function __construct(
        AppliedGiftCardRepositoryInterface $appliedGiftCardRepository,
        Connector $leatConnector
    ) {
        $this->appliedGiftCardRepository = $appliedGiftCardRepository;
        $this->leatConnector = $leatConnector;
    }

    /**
     * Transfer applied gift cards from quote to order
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $quote = $observer->getEvent()->getQuote();
        $order = $observer->getEvent()->getOrder();

        // Skip if no quote or order
        if (!$quote || !$order) {
            return;
        }

        try {
            // Get applied gift cards from quote
            $appliedGiftCards = $this->appliedGiftCardRepository->getByQuoteId((int)$quote->getId());

            // Transfer each gift card to the order
            foreach ($appliedGiftCards as $giftCard) {
                // Clone the gift card and associate with order
                $orderGiftCard = clone $giftCard;
                $orderGiftCard->setId(null); // Reset ID for new record
                $orderGiftCard->setQuoteId(null);
                $orderGiftCard->setOrderId((int)$order->getId());

                // Save the gift card associated with the order
                $this->appliedGiftCardRepository->save($orderGiftCard);
            }
        } catch (\Exception $e) {
            $this->leatConnector->getLogger(ApplicationService::LOGGER_PURPOSE)->log(
                'Error transferring gift cards to order: ' . $e->getMessage(),
                false,
                [
                    'quote_id' => $quote->getId(),
                    'order_id' => $order->getId(),
                    'exception_trace' => $e->getTraceAsString(),
                ]
            );
        }
    }
}
