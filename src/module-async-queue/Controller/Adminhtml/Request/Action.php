<?php

declare(strict_types=1);

namespace Leat\AsyncQueue\Controller\Adminhtml\Request;

use Leat\AsyncQueue\Api\RequestRepositoryInterface;
use Leat\AsyncQueue\Controller\Adminhtml\AbstractAction;
use Leat\AsyncQueue\Service\QueueAdmin;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session;

class Action extends AbstractAction
{
    public function __construct(
        Context $context,
        Session $authSession,
        private readonly RequestRepositoryInterface $requestRepository,
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
                $this->queueAdmin->resetRequest($id);
                return null;
            case 'sync':
                $this->queueAdmin->markRequestSynced($id, (string) $this->authSession->getUser()?->getUserName());
                return null;
            default:
                // Requests run in order inside their job, so retrying a request means running its job.
                $jobId = $this->requestRepository->get($id)->getJobId();
                return $this->queueAdmin->retryJob($jobId)
                    ? null
                    : (string) __('Job #%1 was run but still has failing requests.', $jobId);
        }
    }
}
