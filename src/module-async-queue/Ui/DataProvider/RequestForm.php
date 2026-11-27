<?php

declare(strict_types=1);

namespace Leat\AsyncQueue\Ui\DataProvider;

use Leat\AsyncQueue\Api\RequestRepositoryInterface;
use Leat\AsyncQueue\Controller\Adminhtml\Request\Save;
use Leat\AsyncQueue\Model\Request;
use Leat\AsyncQueue\Model\RequestStatus;
use Leat\AsyncQueue\Model\ResourceModel\Request\CollectionFactory;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Ui\DataProvider\AbstractDataProvider;

class RequestForm extends AbstractDataProvider
{
    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        private readonly RequestInterface $request,
        private readonly RequestRepositoryInterface $requestRepository,
        private readonly AuthorizationInterface $authorization,
        private readonly DataPersistorInterface $dataPersistor,
        CollectionFactory $collectionFactory,
        array $meta = [],
        array $data = []
    ) {
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
        // The form component filters the provider's collection by id; getData() below does the loading itself.
        $this->collection = $collectionFactory->create();
    }

    /**
     * @inheritDoc
     */
    public function getData(): array
    {
        $id = (int) $this->request->getParam($this->getRequestFieldName());
        try {
            /** @var Request $request */
            $request = $this->requestRepository->get($id);
        } catch (NoSuchEntityException) {
            return [];
        }

        $manage = $this->authorization->isAllowed('Leat_AsyncQueue::manage');
        $data = [
            'request_id' => $request->getRequestId(),
            'job_id' => $request->getJobId(),
            'type_code' => $request->getTypeCode(),
            'status' => (string) $this->statusLabel(RequestStatus::of($request)),
            'attempt' => $request->getAttempt(),
            'is_synced' => $request->getIsSynced() ? '1' : '0',
            'payload' => $this->pretty($request->getData(Request::PAYLOAD)),
            'result' => $this->pretty($request->getData(Request::RESULT)),
            'latest_fail_reason' => $request->getLatestFailReason(),
            'created_at' => $request->getCreatedAt(),
            'updated_at' => $request->getUpdatedAt(),
            'lock_manage' => !$manage,
            'lock_payload' => !($manage && $this->authorization->isAllowed('Leat_AsyncQueue::edit_payload')),
            'lock_sync' => !$this->authorization->isAllowed('Leat_AsyncQueue::mark_synced'),
        ];

        // A rejected save (invalid JSON in particular) puts what was typed back.
        $persisted = $this->dataPersistor->get(Save::PERSISTOR_KEY);
        if ($persisted) {
            $data = array_replace($data, array_intersect_key($persisted, array_flip(
                ['attempt', 'is_synced', 'payload']
            )));
            $this->dataPersistor->clear(Save::PERSISTOR_KEY);
        }

        return [$id => $data];
    }

    /**
     * Stored JSON is compact, show it readable. Anything that is not JSON is shown as stored.
     *
     * @param string|null $stored
     * @return string
     */
    private function pretty(?string $stored): string
    {
        if ($stored === null || $stored === '') {
            return '';
        }
        $decoded = json_decode($stored, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return $stored;
        }
        return (string) json_encode($decoded, self::JSON_FLAGS);
    }

    /**
     * @param string $status
     * @return string
     */
    private function statusLabel(string $status): string
    {
        return (string) (array_column((new RequestStatus())->toOptionArray(), 'label', 'value')[$status] ?? $status);
    }
}
