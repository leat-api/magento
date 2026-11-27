<?php

declare(strict_types=1);

namespace Leat\AsyncQueue\Controller\Adminhtml\Job;

use Leat\AsyncQueue\Controller\Adminhtml\AbstractSave;

class Save extends AbstractSave
{
    public const PERSISTOR_KEY = 'leat_asyncqueue_job';
    public const ID_PARAM = 'job_id';
    public const LABEL = 'Job';

    /**
     * @inheritDoc
     */
    protected function persist(int $id, array $data): void
    {
        $this->queueAdmin->saveJob(
            $id,
            $data,
            $this->allowed('Leat_AsyncQueue::manage'),
            $this->allowed('Leat_AsyncQueue::mark_synced'),
            $this->userName()
        );
    }
}
