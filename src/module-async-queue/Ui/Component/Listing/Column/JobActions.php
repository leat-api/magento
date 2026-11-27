<?php

declare(strict_types=1);

namespace Leat\AsyncQueue\Ui\Component\Listing\Column;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Row actions of the job grid. Skipping work only appears for admins who may do it, and asks first.
 */
class JobActions extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        private readonly AuthorizationInterface $authorization,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * @inheritDoc
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }
        $name = $this->getData('name');
        foreach ($dataSource['data']['items'] as &$item) {
            $id = (int) $item['job_id'];
            $item[$name]['edit'] = [
                'href' => $this->urlBuilder->getUrl('leat_asyncqueue/job/edit', ['id' => $id]),
                'label' => __('View / edit'),
            ];
            if ($this->authorization->isAllowed('Leat_AsyncQueue::manage')) {
                $item[$name]['reset'] = [
                    'href' => $this->urlBuilder->getUrl('leat_asyncqueue/job/action', ['do' => 'reset', 'id' => $id]),
                    'post' => true,
                    'label' => __('Reset attempts'),
                ];
                if (!$item['completed']) {
                    $item[$name]['retry'] = [
                        'href' => $this->urlBuilder->getUrl('leat_asyncqueue/job/action', ['do' => 'retry', 'id' => $id]),
                        'post' => true,
                        'label' => __('Retry now'),
                    ];
                }
            }
            if ($this->authorization->isAllowed('Leat_AsyncQueue::mark_synced') && !$item['completed']) {
                $item[$name]['sync'] = [
                    'href' => $this->urlBuilder->getUrl('leat_asyncqueue/job/action', ['do' => 'sync', 'id' => $id]),
                    'post' => true,
                    'label' => __('Mark as completed'),
                    'confirm' => [
                        'title' => __('Mark job #%1 as completed', $id),
                        'message' => __('Skip every open request of this job without sending it to Leat and close the job?'),
                    ],
                ];
            }
        }
        return $dataSource;
    }
}
