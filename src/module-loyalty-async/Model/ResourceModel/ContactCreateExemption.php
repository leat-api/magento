<?php

declare(strict_types=1);

namespace Leat\LoyaltyAsync\Model\ResourceModel;

use Leat\AsyncQueue\Model\ResourceModel\Job\Grid\BlockExemptionInterface;
use Leat\LoyaltyAsync\Model\Queue\Type\Contact\ContactCreate;
use Magento\Framework\App\ResourceConnection;

/**
 * SQL twin of JobQueries::hasContactCreateRequest(), which the JobValidation plugin uses to let a job run
 * while earlier jobs of the customer are open. Keep the two in step.
 */
class ContactCreateExemption implements BlockExemptionInterface
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * @inheritDoc
     */
    public function getSql(string $jobAlias): string
    {
        $connection = $this->resource->getConnection();
        return sprintf(
            'EXISTS (SELECT 1 FROM %1$s AS ce_r JOIN %2$s AS ce_j ON ce_r.job_id = ce_j.job_id'
            . ' WHERE ce_j.relation_id = %3$s.relation_id AND ce_r.type_code = %4$s AND ce_r.is_synced = 0'
            . ' AND ce_j.job_id != %3$s.job_id)',
            $connection->getTableName('async_queue_request'),
            $connection->getTableName('async_queue_job'),
            $jobAlias,
            $connection->quote(ContactCreate::getTypeCode())
        );
    }
}
