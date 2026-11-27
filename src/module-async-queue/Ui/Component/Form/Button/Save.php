<?php

declare(strict_types=1);

namespace Leat\AsyncQueue\Ui\Component\Form\Button;

class Save extends Generic
{
    /**
     * @inheritDoc
     */
    public function getButtonData(): array
    {
        if (!$this->authorization->isAllowed('Leat_AsyncQueue::manage')) {
            return [];
        }

        $form = $this->isJobPage() ? 'leat_asyncqueue_job_form' : 'leat_asyncqueue_request_form';

        return [
            'label' => __('Save'),
            'class' => 'save primary',
            'data_attribute' => [
                'mage-init' => [
                    'buttonAdapter' => [
                        'actions' => [
                            ['targetName' => $form . '.' . $form, 'actionName' => 'save', 'params' => [false]],
                        ],
                    ],
                ],
                'form-role' => 'save',
            ],
            'sort_order' => 90,
        ];
    }
}
