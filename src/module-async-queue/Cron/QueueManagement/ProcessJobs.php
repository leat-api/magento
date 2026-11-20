<?php


declare(strict_types=1);

namespace Leat\AsyncQueue\Cron\QueueManagement;

use Leat\AsyncQueue\Service\JobDigest;
use Magento\Framework\Lock\LockManagerInterface;

class ProcessJobs
{
    private const string LOCK_NAME = 'leat_asyncqueue_process_jobs';

    public function __construct(
        protected JobDigest $queueDigest,
        protected LockManagerInterface $lockManager
    ) {
    }

    /**
     * A run that outlasts its minute must not overlap with the next one.
     *
     * @return void
     * @throws \Magento\Framework\Exception\AuthenticationException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function execute()
    {
        if (!$this->lockManager->lock(self::LOCK_NAME, 0)) {
            return;
        }

        try {
            $this->queueDigest->execute();
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }
}
