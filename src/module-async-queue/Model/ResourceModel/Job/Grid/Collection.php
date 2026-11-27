<?php

declare(strict_types=1);

namespace Leat\AsyncQueue\Model\ResourceModel\Job\Grid;

use Leat\AsyncQueue\Model\RequestStatus;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface as FetchStrategy;
use Magento\Framework\Data\Collection\EntityFactoryInterface as EntityFactory;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Psr\Log\LoggerInterface as Logger;

/**
 * Job admin grid: the job plus per job request totals.
 * ponytail: the request aggregate is a derived table over the whole request table (cleaned up monthly);
 * switch to per page correlated subqueries if the table ever gets large enough to make the grid slow.
 */
class Collection extends SearchResult
{
    /**
     * @var BlockExemptionInterface[]
     */
    private array $blockExemptions;

    /**
     * @param BlockExemptionInterface[] $blockExemptions jobs the cron lets through although earlier jobs are open
     */
    public function __construct(
        EntityFactory $entityFactory,
        Logger $logger,
        FetchStrategy $fetchStrategy,
        EventManager $eventManager,
        $mainTable,
        $resourceModel = null,
        $identifierName = null,
        $connectionName = null,
        array $blockExemptions = []
    ) {
        // Set first: the parent constructor builds the select.
        $this->blockExemptions = $blockExemptions;
        parent::__construct(
            $entityFactory,
            $logger,
            $fetchStrategy,
            $eventManager,
            $mainTable,
            $resourceModel,
            $identifierName,
            $connectionName
        );
    }

    /**
     * @inheritDoc
     */
    // phpcs:ignore
    protected function _initSelect()
    {
        parent::_initSelect();

        $connection = $this->getConnection();
        $agg = $connection->select()->from(
            $connection->getTableName('async_queue_request'),
            [
                'agg_job_id' => 'job_id',
                'agg_total' => new \Zend_Db_Expr('COUNT(*)'),
                'agg_synced' => new \Zend_Db_Expr('SUM(is_synced = 1)'),
                'agg_failing' => new \Zend_Db_Expr('SUM' . RequestStatus::failingSql()),
                'agg_exhausted' => new \Zend_Db_Expr('SUM' . RequestStatus::exhaustedSql()),
                'agg_max_attempt' => new \Zend_Db_Expr('MAX(attempt)'),
            ]
        )->group('job_id');

        // A completed job is finished: the cron never runs it again, so leftover failures on it are not actionable.
        $hasFailures = new \Zend_Db_Expr('(main_table.completed = 0 AND COALESCE(agg.agg_failing, 0) > 0)');
        $needsIntervention = new \Zend_Db_Expr('(main_table.completed = 0 AND COALESCE(agg.agg_exhausted, 0) > 0)');
        $customer = new \Zend_Db_Expr(
            "(SELECT CONCAT(ce.firstname, ' ', ce.lastname, ' <', ce.email, '>')"
            . ' FROM ' . $connection->getTableName('customer_entity') . ' AS ce'
            . ' WHERE ce.entity_id = CAST(main_table.relation_id AS UNSIGNED))'
        );
        // Oldest earlier job of the same customer that is still open: this is what holds the job back.
        // Unless an exemption applies: then the cron runs the job anyway (see JobDigest::validateJob plugins).
        $exempt = array_map(fn(BlockExemptionInterface $e) => $e->getSql('main_table'), $this->blockExemptions);
        $notExempt = $exempt ? ' AND NOT (' . implode(' OR ', $exempt) . ')' : '';
        $blockedBy = new \Zend_Db_Expr(
            '(CASE WHEN main_table.completed = 0 AND main_table.relation_id IS NOT NULL' . $notExempt . ' THEN'
            . ' (SELECT MIN(j2.job_id) FROM ' . $connection->getTableName('async_queue_job') . ' AS j2'
            . ' WHERE j2.relation_id = main_table.relation_id AND j2.completed = 0'
            . ' AND j2.job_id < main_table.job_id) END)'
        );

        $isBlocked = new \Zend_Db_Expr('(' . $blockedBy . ' IS NOT NULL)');

        $this->getSelect()
            ->joinLeft(['agg' => $agg], 'agg.agg_job_id = main_table.job_id', [])
            ->columns([
                'request_count' => new \Zend_Db_Expr('COALESCE(agg.agg_total, 0)'),
                'synced_count' => new \Zend_Db_Expr('COALESCE(agg.agg_synced, 0)'),
                'max_attempt' => new \Zend_Db_Expr('COALESCE(agg.agg_max_attempt, 0)'),
                'has_failures' => $hasFailures,
                'needs_intervention' => $needsIntervention,
                'customer' => $customer,
                'is_blocked' => $isBlocked,
                'blocked_by' => $blockedBy,
            ]);

        // Aliases cannot be used in WHERE, so filters on computed columns need the expression.
        $this->addFilterToMap('has_failures', $hasFailures);
        $this->addFilterToMap('attention', $hasFailures);
        $this->addFilterToMap('needs_intervention', $needsIntervention);
        $this->addFilterToMap('is_blocked', $isBlocked);
        $this->addFilterToMap('customer', $customer);
        $this->addFilterToMap('request_count', new \Zend_Db_Expr('COALESCE(agg.agg_total, 0)'));
        $this->addFilterToMap('synced_count', new \Zend_Db_Expr('COALESCE(agg.agg_synced, 0)'));
        $this->addFilterToMap('max_attempt', new \Zend_Db_Expr('COALESCE(agg.agg_max_attempt, 0)'));

        return $this;
    }
}
