<?php

declare(strict_types=1);

namespace Leat\AsyncQueue\Controller\Adminhtml;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;

/**
 * Reset, retry or skip one job or request (`id`), chosen with the `do` param. POST only: it changes data.
 */
abstract class AbstractAction extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Leat_AsyncQueue::manage';

    public function __construct(
        Context $context,
        protected readonly Session $authSession
    ) {
        parent::__construct($context);
    }

    /**
     * @param string $do
     * @param int $id
     * @return string|null a warning to show instead of the plain success message
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    abstract protected function apply(string $do, int $id): ?string;

    /**
     * @inheritDoc
     */
    // phpcs:ignore
    protected function _isAllowed()
    {
        // Skipping work (sync) is its own permission; reset and retry need manage. Unknown actions are denied.
        $acl = match ((string) $this->getRequest()->getParam('do')) {
            'reset', 'retry' => 'Leat_AsyncQueue::manage',
            'sync' => 'Leat_AsyncQueue::mark_synced',
            default => null,
        };
        return $acl && $this->_authorization->isAllowed($acl);
    }

    /**
     * @inheritDoc
     */
    public function execute(): ResultInterface
    {
        $id = (int) $this->getRequest()->getParam('id');
        try {
            $warning = $this->apply((string) $this->getRequest()->getParam('do'), $id);
            $warning
                ? $this->messageManager->addWarningMessage($warning)
                : $this->messageManager->addSuccessMessage(__('Done for #%1.', $id));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $this->resultRedirectFactory->create()->setRefererOrBaseUrl();
    }
}
