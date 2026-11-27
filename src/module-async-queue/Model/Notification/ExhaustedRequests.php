<?php

declare(strict_types=1);

namespace Leat\AsyncQueue\Model\Notification;

use Leat\AsyncQueue\Model\RequestStatus;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Notification\MessageInterface;
use Magento\Framework\UrlInterface;

/**
 * Admin system message: some requests ran out of retries and wait for a person.
 */
class ExhaustedRequests implements MessageInterface
{
    private const CACHE_KEY = 'leat_asyncqueue_exhausted_count';
    // The message shows on every admin page; a minute of staleness after an admin fixed something is fine.
    private const CACHE_LIFETIME = 60;

    private ?int $count = null;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly UrlInterface $urlBuilder,
        private readonly AuthorizationInterface $authorization,
        private readonly CacheInterface $cache
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getIdentity(): string
    {
        return hash('sha256', 'LEAT_ASYNCQUEUE_EXHAUSTED');
    }

    /**
     * @inheritDoc
     */
    public function isDisplayed(): bool
    {
        return $this->authorization->isAllowed('Leat_AsyncQueue::jobs') && $this->count() > 0;
    }

    /**
     * @inheritDoc
     */
    public function getText(): string
    {
        return (string) __(
            '%1 Leat queue request(s) ran out of retries and need admin intervention. <a href="%2">Review them</a>.',
            $this->count(),
            $this->urlBuilder->getUrl('leat_asyncqueue/job/index', ['needs_intervention' => 1])
        );
    }

    /**
     * @inheritDoc
     */
    public function getSeverity(): int
    {
        return self::SEVERITY_MAJOR;
    }

    /**
     * @return int
     */
    private function count(): int
    {
        if ($this->count === null) {
            $cached = $this->cache->load(self::CACHE_KEY);
            if ($cached === false) {
                $connection = $this->resource->getConnection();
                $cached = (string) $connection->fetchOne(
                    $connection->select()
                        ->from(['r' => $this->resource->getTableName('async_queue_request')], 'COUNT(*)')
                        ->join(['j' => $this->resource->getTableName('async_queue_job')], 'j.job_id = r.job_id', [])
                        ->where('j.completed = 0')
                        ->where(RequestStatus::exhaustedSql('r'))
                );
                $this->cache->save($cached, self::CACHE_KEY, [], self::CACHE_LIFETIME);
            }
            $this->count = (int) $cached;
        }
        return $this->count;
    }
}
