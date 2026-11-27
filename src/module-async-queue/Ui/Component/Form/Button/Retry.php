<?php

declare(strict_types=1);

namespace Leat\AsyncQueue\Ui\Component\Form\Button;

class Retry extends Generic
{
    /**
     * Run the job right now. On a request page this runs the request's job: requests run in order.
     *
     * @inheritDoc
     */
    public function getButtonData(): array
    {
        if (!$this->authorization->isAllowed('Leat_AsyncQueue::manage')) {
            return [];
        }

        return [
            'label' => __('Retry job now'),
            'data_attribute' => ['mage-init' => ['Leat_AsyncQueue/js/confirm-redirect' => [
                'url' => $this->actionUrl('retry', true),
            ]]],
            'class' => 'action-secondary',
            'sort_order' => 30,
        ];
    }
}
