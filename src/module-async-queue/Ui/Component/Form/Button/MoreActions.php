<?php

declare(strict_types=1);

namespace Leat\AsyncQueue\Ui\Component\Form\Button;

use Magento\Ui\Component\Control\Container;

/**
 * Split button: resetting is the default, skipping (mark as synced) hides in the dropdown behind a confirmation.
 */
class MoreActions extends Generic
{
    /**
     * @inheritDoc
     */
    public function getButtonData(): array
    {
        $options = [];
        if ($this->authorization->isAllowed('Leat_AsyncQueue::mark_synced')) {
            $label = $this->isJobPage() ? __('Mark job as completed') : __('Mark as synced');
            $options[] = [
                'id_hard' => 'leat_asyncqueue_mark_synced',
                'label' => $label,
                'data_attribute' => ['mage-init' => ['Leat_AsyncQueue/js/confirm-redirect' => [
                    'url' => $this->actionUrl('sync'),
                    'title' => (string) $label,
                    'message' => (string) ($this->isJobPage()
                        ? __('Skip every open request of this job without sending it to Leat and close the job?')
                        : __('Skip this request without sending it to Leat? Later requests in the job will not '
                            . 'receive a result from it.')),
                ]]],
            ];
        }

        if (!$this->authorization->isAllowed('Leat_AsyncQueue::manage')) {
            return [];
        }

        return [
            'label' => $this->isJobPage() ? __('Reset failed requests') : __('Reset attempts'),
            'class' => 'primary',
            'data_attribute' => ['mage-init' => ['Leat_AsyncQueue/js/confirm-redirect' => [
                'url' => $this->actionUrl('reset'),
            ]]],
            'class_name' => Container::SPLIT_BUTTON,
            'options' => $options,
            'sort_order' => 40,
        ];
    }
}
