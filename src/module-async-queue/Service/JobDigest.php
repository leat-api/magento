<?php

declare(strict_types=1);

namespace Leat\AsyncQueue\Service;

use Leat\AsyncQueue\Api\JobRepositoryInterface;
use Leat\AsyncQueue\Exception\IllegalJobException;
use Leat\AsyncQueue\Model\Job;
use Leat\AsyncQueue\Model\Queue\Request\RequestTypePool;
use Leat\AsyncQueue\Model\Queue\Request\TypeInterface;
use Leat\AsyncQueue\Model\Request;
use Leat\AsyncQueue\Model\RequestRepository;
use Leat\AsyncQueue\Model\ResourceModel\Job\Collection as JobCollection;
use Leat\AsyncQueue\Model\ResourceModel\Job\CollectionFactory as JobCollectionFactory;
use Magento\Framework\Exception\AuthenticationException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;

class JobDigest
{
    public const string PREVIOUS_RESULT = 'previous_result';

    /**
     * Prefix of the per job lock name (Magento lock manager)
     */
    public const string LOCK_PREFIX = 'leat_asyncqueue_job_';

    /**
     * Limit the amount of jobs to be processed at once to prevent memory/timing issues.
     */
    private const int MAX_JOB_EXECUTIONS = 500;

    /**
     * Amount of attempts before a request is considered failed.
     * - Will need manual intervention to resolve the issue.
     */
    private const int MAX_RETRY_ATTEMPTS = 8;

    /**
     * Used in a linear progression.
     * The first attempt
     */
    private const int RETRY_DELAY = 2;
    private const string RETRY_DELAY_FORMAT = "%d hours";

    /**
     * When running a single job, this value will be set.
     * @var Job
     */
    private Job $job;

    public function __construct(
        protected JobRepositoryInterface $jobRepository,
        protected RequestRepository      $requestRepository,
        protected JobCollectionFactory   $jobCollectionFactory,
        protected DateTime               $dateTime,
        protected RequestTypePool        $requestTypePool,
        protected LockManagerInterface   $lockManager
    ) {
    }

    /**
     * Retrieve and execute all pending jobs.
     * - If a request cannot be successfully executed, continue on to the next job to
     *   adhere to the sequential order of requests in a job.
     * - Checks for parent jobs for the current customer that have yet to be completed.
     *   In this case, the job will also be skipped.
     *
     * @return void
     * @throws AuthenticationException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function execute(): void
    {
        /** @var Job $job */
        foreach ($this->getJobs() as $job) {
            // One runner per job: the cron and an immediate run (e.g. an earn points claim) must not
            // execute the same request twice. A job that is locked is being handled by someone else.
            $lockName = self::LOCK_PREFIX . $job->getJobId();
            if (!$this->lockManager->lock($lockName, 0)) {
                continue;
            }

            try {
                $this->processJob($job);
            } finally {
                $this->lockManager->unlock($lockName);
            }
        }
    }

    /**
     * Execute the requests of one job. The caller holds the job lock.
     *
     * @param Job $job
     * @return void
     * @throws AuthenticationException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function processJob(Job $job): void
    {
        $typePool = $this->requestTypePool->getRequestTypes();
        $previousRequestResult = null;
        $skipValidation = ($job->getSkipValidation() ?? false);

        // Another runner may have finished this job between loading it and getting the lock
        if ($this->jobRepository->get($job->getJobId())->getCompleted()) {
            return;
        }
        $job->getRequestCollection()->clear();

        if (!$skipValidation && !$this->validateJob($job)) {
            return;
        }

        /** @var Request[] $requests */
        $requests = $job->getRequestCollection();
        foreach ($requests as $request) {
            if ($request->getIsSynced()) {
                $previousRequestResult = $request->getResult();
                continue;
            }

            if (!$skipValidation && !$this->checkRetryLimit($request)) {
                return;
            }

            $exception = null;
            try {
                /** @var TypeInterface $type */
                $request->setAttempt(($request->getAttempt() + 1));
                $type = $typePool[$request->getTypeCode()];

                $type->unpack([...$request->getPayload(), self::PREVIOUS_RESULT => $previousRequestResult]);

                $type->beforeExecute($job, $request);

                $request->setIsSynced(true);
            } catch (\Throwable $exception) {
                $this->handleException($job, $request, $exception);
            } finally {
                try {
                    $this->requestRepository->save($request);
                } catch (\Throwable $saveException) {
                    // Log the save exception
                    $type->getConnector()->getLogger()->log(
                        'Error saving request: ' . $saveException->getMessage()
                    );

                    if ($exception === null) {
                        $exception = $saveException;
                    }
                }
            }

            if ($exception !== null) {
                // Continue running the next job as the requests in a job are sequential
                return;
            }
            $previousRequestResult = $request->getResult();
        }

        $job->setCompleted(true);
        $this->jobRepository->save($job);
    }

    /**
     * Check if the request is allowed to be executed.
     * - Delay is built into the retry period to allow the external service to come back online from
     *   extended maintenance if necessary.
     * - The second attempt will be instant, the third attempt will be after two hours,
     * - After eight attempts, twenty-four hours of delay have been given. At this point
     *   it's more than certain that the request has issues that need to be addressed.
     *
     * @param Request $request
     * @return bool
     */
    protected function checkRetryLimit(Request $request): bool
    {
        if ($this->skipRetryLimit()) {
            return true;
        }

        $attempt = (int) ($request->getAttempt()  - 1);
        if ($attempt < 1) {
            return true;
        }

        if ($attempt >= self::MAX_RETRY_ATTEMPTS) {
            return false;
        }

        $delay = sprintf(self::RETRY_DELAY_FORMAT, (self::RETRY_DELAY * $attempt));
        $retryAt = strtotime($delay, $this->dateTime->timestamp($request->getUpdatedAt()));

        return $retryAt <= time();
    }

    /**
     * @return false
     */
    public function skipRetryLimit(): false
    {
        return false;
    }

    /**
     * Validate job before execution
     *
     * @param Job $job
     * @return bool
     */
    public function validateJob(Job $job): bool
    {
        $result = true;
        if ($this->jobRepository->hasUncompletedParent($job)) {
            $result = false;
        }

        return $result;
    }

    /**
     * Handle the exception so that it is clear why the request has failed and to ensure correct handling.
     * - In case no known exception is thrown, the exception will be inserted instead.
     *
     * @param Job $job
     * @param Request $request
     * @param $exception
     * @return void
     */
    public function handleException(Job $job, Request $request, $exception): void
    {
        switch (true) {
            case $exception instanceof IllegalJobException:
                // When a job is not allowed to be executed, it will be marked as completed.
                $request->setLatestFailReason($exception->getMessage());
                $request->setIsSynced(true);
                $job->setCompleted(true);
                break;
            default:
                $request->setLatestFailReason(sprintf(
                    "%s \n %s",
                    $exception->getMessage(),
                    $exception->getTraceAsString()
                ));
                break;
        }
    }

    /**
     * Get all jobs that still require syncing to the external services.
     *
     * @return JobCollection|array
     */
    protected function getJobs(): JobCollection|array
    {
        if (isset($this->job)) {
            $job = $this->job;
            unset($this->job);
            return [$job];
        }

        /** @var JobCollection $collection */
        $collection = $this->jobCollectionFactory->create();
        $collection->addFilter('completed', false);

        // Limit the amount of jobs to be processed at once to prevent memory/timing issues.
        $collection->setPageSize(self::MAX_JOB_EXECUTIONS);
        $collection->setCurPage(1);

        return $collection;
    }

    /**
     * Set a single job to run - for use in jobs that need direct syncing instead of async.
     * - Upon failure or when parent jobs exist, it will be handled by the async queue.
     *
     * @param Job $job
     * @return JobDigest
     */
    public function setJob(Job $job): static
    {
        $this->job = $job;
        return $this;
    }
}
