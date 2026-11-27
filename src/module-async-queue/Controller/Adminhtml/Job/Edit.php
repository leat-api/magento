<?php

declare(strict_types=1);

namespace Leat\AsyncQueue\Controller\Adminhtml\Job;

use Leat\AsyncQueue\Api\JobRepositoryInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Result\PageFactory;

class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Leat_AsyncQueue::jobs';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly JobRepositoryInterface $jobRepository
    ) {
        parent::__construct($context);
    }

    /**
     * Job form with the listing of its requests.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $id = (int) $this->getRequest()->getParam('id');
        try {
            $this->jobRepository->get($id);
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(__('This job no longer exists.'));
            return $this->resultRedirectFactory->create()->setPath('*/*/');
        }

        $page = $this->pageFactory->create();
        $page->setActiveMenu('Leat_AsyncQueue::jobs');
        $page->getConfig()->getTitle()->prepend(__('Job #%1', $id));
        return $page;
    }
}
