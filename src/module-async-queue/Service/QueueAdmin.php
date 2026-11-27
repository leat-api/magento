<?php

declare(strict_types=1);

namespace Leat\AsyncQueue\Service;

use Leat\AsyncQueue\Api\Data\JobInterface;
use Leat\AsyncQueue\Api\Data\RequestInterface;
use Leat\AsyncQueue\Api\JobRepositoryInterface;
use Leat\AsyncQueue\Api\RequestRepositoryInterface;
use Leat\AsyncQueue\Model\Job;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Everything an admin can do to jobs and requests. Permissions are checked by the controllers,
 * this class only guards the data: it never changes a job the cron is currently working on.
 */
class QueueAdmin
{
    public function __construct(
        private readonly JobRepositoryInterface $jobRepository,
        private readonly RequestRepositoryInterface $requestRepository,
        private readonly JobDigest $jobDigest,
        private readonly LockManagerInterface $lockManager,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * Set the attempts of a failed request back to zero so the cron picks it up again straight away.
     *
     * @param int $requestId
     * @return void
     * @throws LocalizedException
     */
    public function resetRequest(int $requestId): void
    {
        $jobId = $this->requestRepository->get($requestId)->getJobId();
        $this->locked($jobId, function () use ($requestId, $jobId): void {
            $request = $this->requestRepository->get($requestId);
            if ($request->getIsSynced()) {
                throw new LocalizedException(__('Request #%1 is already synced.', $requestId));
            }
            $request->setAttempt(0);
            $this->requestRepository->save($request);
            $this->setJobCompleted($this->job($jobId), false);
        });
    }

    /**
     * Reset the attempts of every failed request of the job.
     *
     * @param int $jobId
     * @return void
     * @throws LocalizedException
     */
    public function resetJob(int $jobId): void
    {
        $this->locked($jobId, function () use ($jobId): void {
            $job = $this->job($jobId);
            $reset = 0;
            foreach ($job->getRequestCollection()->clear() as $request) {
                if (!$request->getIsSynced() && $request->getAttempt() > 0) {
                    $request->setAttempt(0);
                    $this->requestRepository->save($request);
                    $reset++;
                }
            }
            if (!$reset) {
                throw new LocalizedException(__('Job #%1 has no failed requests to reset.', $jobId));
            }
            $this->setJobCompleted($job, false);
        });
    }

    /**
     * Skip a request without executing it. Later requests in the job receive no previous result from it.
     *
     * @param int $requestId
     * @param string $actor admin user name, written to the request for traceability
     * @return void
     * @throws LocalizedException
     */
    public function markRequestSynced(int $requestId, string $actor): void
    {
        $jobId = $this->requestRepository->get($requestId)->getJobId();
        $this->locked($jobId, function () use ($requestId, $jobId, $actor): void {
            $request = $this->requestRepository->get($requestId);
            if ($request->getIsSynced()) {
                throw new LocalizedException(__('Request #%1 is already synced.', $requestId));
            }
            $this->syncRequest($request, $actor);
            $this->completeIfAllSynced($this->job($jobId));
        });
    }

    /**
     * Skip every open request of the job and close it.
     *
     * @param int $jobId
     * @param string $actor
     * @return void
     * @throws LocalizedException
     */
    public function markJobSynced(int $jobId, string $actor): void
    {
        $this->locked($jobId, fn() => $this->syncJob($this->job($jobId), $actor));
    }

    /**
     * Run the job now, ignoring the retry delay and the retry limit.
     * - A job that waits on an earlier uncompleted job of the same customer is not run, that would break the order.
     *   Everything else the cron would hold back (retry delay, retry limit) is skipped: the admin asked for it now.
     *
     * @param int $jobId
     * @return bool whether the job is completed afterwards
     * @throws LocalizedException
     */
    public function retryJob(int $jobId): bool
    {
        $job = $this->job($jobId);
        if ($job->getCompleted()) {
            throw new LocalizedException(__('Job #%1 is already completed.', $jobId));
        }
        $this->assertNotRunning($jobId);
        // The cron's own check, including the plugin that lets jobs creating the contact go first.
        if (!$this->jobDigest->validateJob($job)) {
            throw new LocalizedException(
                __('Job #%1 waits on an earlier uncompleted job of the same customer. Resolve that one first.', $jobId)
            );
        }

        // Skips validateJob (done above), the retry delay and the retry limit inside the digest.
        $job->setSkipValidation(true);
        if (!$this->jobDigest->executeJob($job)) {
            // The cron took the lock between the check above and now: nothing ran.
            throw $this->runningException($jobId);
        }

        return $this->job($jobId)->getCompleted();
    }

    /**
     * @param int $jobId
     * @param array $data posted form data
     * @param bool $canManage may change relation, source and store
     * @param bool $canSync may complete the job
     * @param string $actor
     * @return void
     * @throws LocalizedException
     */
    public function saveJob(int $jobId, array $data, bool $canManage, bool $canSync, string $actor): void
    {
        $this->locked($jobId, function () use ($jobId, $data, $canManage, $canSync, $actor): void {
            $job = $this->job($jobId);

            if ($canManage) {
                if (array_key_exists('relation_id', $data)) {
                    $job->setData(JobInterface::RELATION_ID, $this->relationId($job, (string) $data['relation_id']));
                }
                if (array_key_exists('source_id', $data)) {
                    $source = trim((string) $data['source_id']);
                    if (strlen($source) > 255) {
                        throw new LocalizedException(__('The source id is too long (255 characters max).'));
                    }
                    $job->setData(JobInterface::SOURCE_ID, $source === '' ? null : $source);
                }
                if (array_key_exists('store_id', $data)) {
                    $job->setData(JobInterface::STORE_ID, $this->storeId((string) $data['store_id']));
                }
                $this->jobRepository->save($job);
            }

            if ($canSync && array_key_exists('completed', $data)) {
                $completed = (bool) (int) $data['completed'];
                if ($completed && !$job->getCompleted()) {
                    $this->syncJob($job, $actor);
                } elseif (!$completed) {
                    $this->setJobCompleted($job, false);
                }
            }
        });
    }

    /**
     * @param int $requestId
     * @param array $data posted form data
     * @param bool $canManage may change the attempts
     * @param bool $canPayload may change the payload (needs $canManage too)
     * @param bool $canSync may change the synced state
     * @param string $actor
     * @return void
     * @throws LocalizedException
     */
    public function saveRequest(
        int $requestId,
        array $data,
        bool $canManage,
        bool $canPayload,
        bool $canSync,
        string $actor
    ): void {
        $jobId = $this->requestRepository->get($requestId)->getJobId();
        $this->locked($jobId, function () use ($requestId, $jobId, $data, $canManage, $canPayload, $canSync, $actor) {
            $request = $this->requestRepository->get($requestId);

            // Only a change that makes the request runnable again may reopen the job; editing a payload does not.
            $reopen = false;
            if ($canManage && ($data['attempt'] ?? '') !== '') {
                $attempt = filter_var($data['attempt'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 127]]);
                if ($attempt === false) {
                    throw new LocalizedException(__('Attempts must be a whole number between 0 and 127.'));
                }
                $reopen = $attempt < $request->getAttempt();
                $request->setAttempt($attempt);
            }

            // Validate before anything is written: a bad payload must not save the other fields either.
            if ($canManage && $canPayload && array_key_exists('payload', $data)) {
                $payload = $this->decodePayload((string) $data['payload']);
                try {
                    $current = $request->getPayload();
                } catch (\Throwable) {
                    $current = null;
                }
                if ($payload !== $current) {
                    $request->setPayload($payload);
                }
            }

            $this->requestRepository->save($request);

            if ($canSync && array_key_exists('is_synced', $data)) {
                $synced = (bool) (int) $data['is_synced'];
                if ($synced && !$request->getIsSynced()) {
                    $this->syncRequest($request, $actor);
                } elseif (!$synced && $request->getIsSynced()) {
                    $request->setIsSynced(false);
                    $this->requestRepository->save($request);
                    $reopen = true;
                }
            }

            if ($request->getIsSynced()) {
                $this->completeIfAllSynced($this->job($jobId));
            } elseif ($reopen) {
                $this->setJobCompleted($this->job($jobId), false);
            }
        });
    }

    /**
     * Payloads are stored as JSON; only accept something that decodes to an object or array.
     *
     * @param string $raw
     * @return array
     * @throws LocalizedException
     */
    public function decodePayload(string $raw): array
    {
        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new LocalizedException(__('The payload is not valid JSON: %1', $e->getMessage()));
        }
        if (!is_array($payload)) {
            throw new LocalizedException(__('The payload must be a JSON object or array.'));
        }
        return $payload;
    }

    /**
     * @param int $jobId
     * @return Job
     * @throws NoSuchEntityException
     */
    private function job(int $jobId): Job
    {
        /** @var Job $job */
        $job = $this->jobRepository->get($jobId);
        return $job;
    }

    /**
     * Run $callback while holding the same per job lock as the cron, or refuse when the cron holds it.
     *
     * @param int $jobId
     * @param callable $callback
     * @return mixed
     * @throws LocalizedException
     */
    private function locked(int $jobId, callable $callback): mixed
    {
        $name = JobDigest::LOCK_PREFIX . $jobId;
        if (!$this->lockManager->lock($name, 0)) {
            throw $this->runningException($jobId);
        }
        try {
            return $callback();
        } finally {
            $this->lockManager->unlock($name);
        }
    }

    /**
     * @param int $jobId
     * @return void
     * @throws LocalizedException
     */
    private function assertNotRunning(int $jobId): void
    {
        if ($this->lockManager->isLocked(JobDigest::LOCK_PREFIX . $jobId)) {
            throw $this->runningException($jobId);
        }
    }

    /**
     * @param int $jobId
     * @return LocalizedException
     */
    private function runningException(int $jobId): LocalizedException
    {
        return new LocalizedException(__('Job #%1 is being processed right now. Try again in a minute.', $jobId));
    }

    /**
     * @param RequestInterface $request
     * @param string $actor
     * @return void
     * @throws LocalizedException
     */
    private function syncRequest(RequestInterface $request, string $actor): void
    {
        $note = sprintf('[Marked as synced manually by %s at %s UTC]', $actor, $this->dateTime->gmtDate());
        $reason = $request->getLatestFailReason();
        // First line, because that is what the grid, config bar and alert email show.
        $request->setLatestFailReason($reason ? $note . "\n" . $reason : $note);
        $request->setIsSynced(true);
        $this->requestRepository->save($request);
    }

    /**
     * @param Job $job
     * @param string $actor
     * @return void
     * @throws LocalizedException
     */
    private function syncJob(Job $job, string $actor): void
    {
        foreach ($job->getRequestCollection()->clear() as $request) {
            if (!$request->getIsSynced()) {
                $this->syncRequest($request, $actor);
            }
        }
        $this->setJobCompleted($job, true);
    }

    /**
     * @param Job $job
     * @return void
     * @throws LocalizedException
     */
    private function completeIfAllSynced(Job $job): void
    {
        foreach ($job->getRequestCollection()->clear() as $request) {
            if (!$request->getIsSynced()) {
                return;
            }
        }
        $this->setJobCompleted($job, true);
    }

    /**
     * @param Job $job
     * @param bool $completed
     * @return void
     * @throws LocalizedException
     */
    private function setJobCompleted(Job $job, bool $completed): void
    {
        if ($job->getCompleted() !== $completed) {
            $job->setCompleted($completed);
            $this->jobRepository->save($job);
        }
    }

    /**
     * @param Job $job
     * @param string $value
     * @return string|null
     * @throws LocalizedException
     */
    private function relationId(Job $job, string $value): ?string
    {
        $value = trim($value);
        if ($value === ($job->getRelationId() ?? '')) {
            return $job->getRelationId();
        }
        if ($value === '') {
            return null;
        }
        if (!ctype_digit($value)) {
            throw new LocalizedException(__('The relation id must be a customer id (digits only).'));
        }
        try {
            $this->customerRepository->getById((int) $value);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('Customer %1 does not exist.', $value));
        }
        return $value;
    }

    /**
     * @param string $value
     * @return int|null
     * @throws LocalizedException
     */
    private function storeId(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (!ctype_digit($value)) {
            throw new LocalizedException(__('The store id must be a number.'));
        }
        try {
            $this->storeManager->getStore((int) $value);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('Store %1 does not exist.', $value));
        }
        return (int) $value;
    }
}
