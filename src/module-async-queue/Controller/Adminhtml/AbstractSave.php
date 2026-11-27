<?php

declare(strict_types=1);

namespace Leat\AsyncQueue\Controller\Adminhtml;

use Leat\AsyncQueue\Service\QueueAdmin;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Controller\ResultInterface;

/**
 * Save of the job and request forms. Subclasses define PERSISTOR_KEY, ID_PARAM and LABEL, and call QueueAdmin.
 */
abstract class AbstractSave extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Leat_AsyncQueue::manage';
    // Overridden by every subclass.
    public const PERSISTOR_KEY = '';
    public const ID_PARAM = '';
    public const LABEL = '';

    public function __construct(
        Context $context,
        protected readonly QueueAdmin $queueAdmin,
        protected readonly Session $authSession,
        protected readonly DataPersistorInterface $dataPersistor
    ) {
        parent::__construct($context);
    }

    /**
     * @param int $id
     * @param array $data
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    abstract protected function persist(int $id, array $data): void;

    /**
     * @param string $acl
     * @return bool
     */
    protected function allowed(string $acl): bool
    {
        return $this->_authorization->isAllowed($acl);
    }

    /**
     * @return string
     */
    protected function userName(): string
    {
        return (string) $this->authSession->getUser()?->getUserName();
    }

    /**
     * @inheritDoc
     */
    public function execute(): ResultInterface
    {
        $id = (int) $this->getRequest()->getParam(static::ID_PARAM);
        $data = (array) $this->getRequest()->getPostValue();

        try {
            $this->persist($id, $data);
            $this->dataPersistor->clear(static::PERSISTOR_KEY);
            $this->messageManager->addSuccessMessage(__('%1 #%2 saved.', static::LABEL, $id));
        } catch (\Throwable $e) {
            // Keep what was typed (a rejected payload in particular) so it can be corrected, not retyped.
            $this->dataPersistor->set(static::PERSISTOR_KEY, $data);
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $this->resultRedirectFactory->create()->setPath('*/*/edit', ['id' => $id]);
    }
}
