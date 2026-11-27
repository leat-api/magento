<?php

declare(strict_types=1);

namespace Leat\AsyncQueue\Controller\Adminhtml\Request;

use Leat\AsyncQueue\Controller\Adminhtml\AbstractSave;

class Save extends AbstractSave
{
    public const PERSISTOR_KEY = 'leat_asyncqueue_request';
    public const ID_PARAM = 'request_id';
    public const LABEL = 'Request';

    /**
     * @inheritDoc
     */
    protected function persist(int $id, array $data): void
    {
        $this->queueAdmin->saveRequest(
            $id,
            $data,
            $this->allowed('Leat_AsyncQueue::manage'),
            $this->allowed('Leat_AsyncQueue::edit_payload'),
            $this->allowed('Leat_AsyncQueue::mark_synced'),
            $this->userName()
        );
    }
}
