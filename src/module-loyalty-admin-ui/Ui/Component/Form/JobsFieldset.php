<?php

declare(strict_types=1);

namespace Leat\LoyaltyAdminUI\Ui\Component\Form;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Ui\Component\Form\Fieldset;

/**
 * Fieldset with the customer's queue jobs. Not rendered at all for admins who may not view the queue.
 */
class JobsFieldset extends Fieldset
{
    public function __construct(
        ContextInterface $context,
        private readonly AuthorizationInterface $authorization,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $components, $data);
    }

    /**
     * @inheritDoc
     */
    public function prepare()
    {
        parent::prepare();
        if (!$this->authorization->isAllowed('Leat_AsyncQueue::jobs')) {
            $this->_data['config']['componentDisabled'] = true;
        }
    }
}
