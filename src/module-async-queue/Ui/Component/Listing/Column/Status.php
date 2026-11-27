<?php

declare(strict_types=1);

namespace Leat\AsyncQueue\Ui\Component\Listing\Column;

use Leat\AsyncQueue\Model\RequestStatus;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Request status as a coloured badge (rendered with the html cell template).
 */
class Status extends Column
{
    private const CLASSES = [
        RequestStatus::SYNCED => 'grid-severity-notice',
        RequestStatus::PENDING => 'grid-severity-minor',
        RequestStatus::FAILING => 'grid-severity-major',
        RequestStatus::EXHAUSTED => 'grid-severity-critical',
    ];

    /**
     * @inheritDoc
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }
        $labels = array_column((new RequestStatus())->toOptionArray(), 'label', 'value');
        foreach ($dataSource['data']['items'] as &$item) {
            $status = $item[$this->getData('name')] ?? RequestStatus::PENDING;
            $item[$this->getData('name')] = sprintf(
                '<span class="%s"><span>%s</span></span>',
                self::CLASSES[$status] ?? 'grid-severity-minor',
                htmlspecialchars((string) ($labels[$status] ?? $status))
            );
        }
        return $dataSource;
    }
}
