<?php

declare(strict_types=1);

namespace Leat\AsyncQueue\Ui\DataProvider;

use Leat\AsyncQueue\Api\JobRepositoryInterface;
use Leat\AsyncQueue\Controller\Adminhtml\Job\Save;
use Leat\AsyncQueue\Cron\QueueManagement\QueueCleanup;
use Leat\AsyncQueue\Model\ResourceModel\Job\CollectionFactory;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Ui\DataProvider\AbstractDataProvider;

class JobForm extends AbstractDataProvider
{
    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        private readonly RequestInterface $request,
        private readonly JobRepositoryInterface $jobRepository,
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
            $job = $this->jobRepository->get($id);
        } catch (NoSuchEntityException) {
            return [];
        }

        $data = [
            'job_id' => $job->getJobId(),
            'relation_id' => $job->getRelationId(),
            'source_id' => $job->getSourceId(),
            'store_id' => $job->getStoreId(),
            'completed' => $job->getCompleted() ? '1' : '0',
            'created_at' => $job->getCreatedAt(),
            'updated_at' => $job->getUpdatedAt(),
            'removal' => $this->removalNote($job->getCompleted(), $job->getCreatedAt()),
            // Read by the form: which fields the admin may not touch.
            'lock_manage' => !$this->authorization->isAllowed('Leat_AsyncQueue::manage'),
            'lock_sync' => !$this->authorization->isAllowed('Leat_AsyncQueue::mark_synced'),
        ];

        // A rejected save puts what was typed back.
        $persisted = $this->dataPersistor->get(Save::PERSISTOR_KEY);
        if ($persisted) {
            $data = array_replace($data, array_intersect_key($persisted, array_flip(
                ['relation_id', 'source_id', 'store_id', 'completed']
            )));
            $this->dataPersistor->clear(Save::PERSISTOR_KEY);
        }

        return [$id => $data];
    }

    /**
     * Completed jobs are deleted by the cleanup cron once they are older than the retention period.
     *
     * @param bool $completed
     * @param string|null $createdAt
     * @return string
     */
    private function removalNote(bool $completed, ?string $createdAt): string
    {
        if (!$completed) {
            return (string) __('Not scheduled: only completed jobs are cleaned up.');
        }
        $removal = date('Y-m-d', strtotime(QueueCleanup::RETENTION, strtotime((string) $createdAt)));
        return (string) __(
            'The cleanup cron removes this job, and its requests, on its first run after %1 (completed jobs are '
            . 'kept for %2 from creation).',
            $removal,
            QueueCleanup::RETENTION
        );
    }
}
