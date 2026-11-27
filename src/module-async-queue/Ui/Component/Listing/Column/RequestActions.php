<?php

declare(strict_types=1);

namespace Leat\AsyncQueue\Ui\Component\Listing\Column;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Row actions of the request grid (embedded in the job page).
 */
class RequestActions extends Column
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
            $id = (int) $item['request_id'];
            $item[$name]['edit'] = [
                'href' => $this->urlBuilder->getUrl('leat_asyncqueue/request/edit', ['id' => $id]),
                'label' => __('View / edit'),
            ];
            if ($item['is_synced']) {
                continue;
            }
            if ($this->authorization->isAllowed('Leat_AsyncQueue::manage')) {
                $item[$name]['reset'] = [
                    'href' => $this->urlBuilder->getUrl('leat_asyncqueue/request/action', ['do' => 'reset', 'id' => $id]),
                    'post' => true,
                    'label' => __('Reset attempts'),
                ];
                $item[$name]['retry'] = [
                    'href' => $this->urlBuilder->getUrl('leat_asyncqueue/request/action', ['do' => 'retry', 'id' => $id]),
                    'post' => true,
                    'label' => __('Retry job now'),
                ];
            }
            if ($this->authorization->isAllowed('Leat_AsyncQueue::mark_synced')) {
                $item[$name]['sync'] = [
                    'href' => $this->urlBuilder->getUrl('leat_asyncqueue/request/action', ['do' => 'sync', 'id' => $id]),
                    'post' => true,
                    'label' => __('Mark as synced'),
                    'confirm' => [
                        'title' => __('Mark request #%1 as synced', $id),
                        'message' => __('Skip this request without sending it to Leat? Later requests in the job will '
                            . 'not receive a result from it.'),
                    ],
                ];
            }
        }
        return $dataSource;
    }
}
