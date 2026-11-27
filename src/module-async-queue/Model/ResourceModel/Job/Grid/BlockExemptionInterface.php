<?php

declare(strict_types=1);

namespace Leat\AsyncQueue\Model\ResourceModel\Job\Grid;

/**
 * A reason why an open job does NOT wait on earlier open jobs of the same customer, as SQL for the job grid.
 * Mirrors the plugins on JobDigest::validateJob(): the cron lets such a job through, so the grid must not call it blocked.
 */
interface BlockExemptionInterface
{
    /**
     * @param string $jobAlias alias of the job table in the grid query, columns job_id and relation_id
     * @return string SQL condition, true when the job is exempt from waiting
     */
    public function getSql(string $jobAlias): string;
}
