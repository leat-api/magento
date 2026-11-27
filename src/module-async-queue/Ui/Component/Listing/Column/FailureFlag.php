<?php

declare(strict_types=1);

namespace Leat\AsyncQueue\Ui\Component\Listing\Column;

use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Yes/No for a problem flag: Yes is red, No is green (rendered with the html cell template).
 */
class FailureFlag extends Column
{
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
            $item[$name] = (int) ($item[$name] ?? 0) === 1
                ? '<span class="grid-severity-critical"><span>' . __('Yes') . '</span></span>'
                : '<span class="grid-severity-notice"><span>' . __('No') . '</span></span>';
        }
        return $dataSource;
    }
}
