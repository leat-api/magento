<?php

declare(strict_types=1);

namespace Leat\AsyncQueue\Ui\Component\Form\Button;

class Back extends Generic
{
    /**
     * @inheritDoc
     */
    public function getButtonData(): array
    {
        $onJob = $this->isJobPage();
        $url = $onJob
            ? $this->urlBuilder->getUrl('leat_asyncqueue/job/index')
            : $this->urlBuilder->getUrl('leat_asyncqueue/job/edit', ['id' => $this->jobId()]);

        return [
            'label' => $onJob ? __('Back to jobs') : __('Back to job'),
            'on_click' => sprintf("location.href = '%s';", $url),
            'class' => 'back',
            'sort_order' => 10,
        ];
    }
}
