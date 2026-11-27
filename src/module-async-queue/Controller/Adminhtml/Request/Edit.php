<?php

declare(strict_types=1);

namespace Leat\AsyncQueue\Controller\Adminhtml\Request;

use Leat\AsyncQueue\Api\RequestRepositoryInterface;
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
        private readonly RequestRepositoryInterface $requestRepository
    ) {
        parent::__construct($context);
    }

    /**
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $id = (int) $this->getRequest()->getParam('id');
        try {
            $request = $this->requestRepository->get($id);
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(__('This request no longer exists.'));
            return $this->resultRedirectFactory->create()->setPath('*/job/');
        }

        $page = $this->pageFactory->create();
        $page->setActiveMenu('Leat_AsyncQueue::jobs');
        $page->getConfig()->getTitle()->prepend(__('Request #%1 (job #%2)', $id, $request->getJobId()));
        return $page;
    }
}
