<?php

declare(strict_types=1);

namespace Leat\AsyncQueue\Model\ResourceModel\Request\Grid;

use Leat\AsyncQueue\Model\RequestStatus;
use Magento\Framework\DB\Select;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

/**
 * Request admin grid. Payload and result are left out, they can be large.
 */
class Collection extends SearchResult
{
    /**
     * @inheritDoc
     */
    // phpcs:ignore
    protected function _initSelect()
    {
        parent::_initSelect();

        $status = new \Zend_Db_Expr(RequestStatus::sql());
        $this->getSelect()
            ->reset(Select::COLUMNS)
            ->columns([
                'request_id', 'job_id', 'type_code', 'is_synced', 'attempt', 'created_at', 'updated_at',
                'status' => $status,
                'fail_summary' => new \Zend_Db_Expr('LEFT(SUBSTRING_INDEX(latest_fail_reason, CHAR(10), 1), 200)'),
            ], 'main_table');

        $this->addFilterToMap('status', $status);
        $this->addFilterToMap('fail_summary', 'main_table.latest_fail_reason');

        return $this;
    }
}
