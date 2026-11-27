<?php

declare(strict_types=1);

namespace Leat\AsyncQueue\Controller\Adminhtml\Job;

use Leat\AsyncQueue\Controller\Adminhtml\AbstractAction;
use Leat\AsyncQueue\Service\QueueAdmin;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session;

class Action extends AbstractAction
{
    public function __construct(
        Context $context,
        Session $authSession,
        private readonly QueueAdmin $queueAdmin
    ) {
        parent::__construct($context, $authSession);
    }

    /**
     * @inheritDoc
     */
    protected function apply(string $do, int $id): ?string
    {
        switch ($do) {
            case 'reset':
                $this->queueAdmin->resetJob($id);
                return null;
            case 'sync':
                $this->queueAdmin->markJobSynced($id, (string) $this->authSession->getUser()?->getUserName());
                return null;
            default:
                return $this->queueAdmin->retryJob($id)
                    ? null
                    : (string) __('Job #%1 was run but still has failing requests.', $id);
        }
    }
}
