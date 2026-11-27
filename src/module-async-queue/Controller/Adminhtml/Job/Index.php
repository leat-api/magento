<?php

declare(strict_types=1);

namespace Leat\AsyncQueue\Controller\Adminhtml\Job;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Leat_AsyncQueue::jobs';

    public function __construct(Context $context, private readonly PageFactory $pageFactory)
    {
        parent::__construct($context);
    }

    /**
     * Listing of all jobs, or only those with failing requests when called with `attention/1`.
     *
     * @return Page
     */
    public function execute(): Page
    {
        $attention = (bool) $this->getRequest()->getParam('attention');
        $page = $this->pageFactory->create();
        $page->setActiveMenu($attention ? 'Leat_AsyncQueue::jobs_attention' : 'Leat_AsyncQueue::jobs');
        $page->getConfig()->getTitle()->prepend($attention ? __('Jobs that need attention') : __('Jobs'));
        return $page;
    }
}
