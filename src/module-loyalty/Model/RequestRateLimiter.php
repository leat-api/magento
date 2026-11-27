<?php

declare(strict_types=1);

namespace Leat\Loyalty\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;

/**
 * Throttles incoming storefront/REST requests that are abusable without authentication (enumeration, brute force).
 * Not to be confused with RateLimiter, which paces our outgoing calls to the Leat API.
 *
 * A bucket is keyed by usage + customer id + client IP, so one feature never eats another feature's allowance.
 * The IP comes from Magento's RemoteAddress: behind a proxy/CDN the project must configure that class
 * (`alternativeHeaders` + `trustedProxies` in di.xml), otherwise every visitor shares the proxy's IP.
 */
class RequestRateLimiter
{
    private const CACHE_PREFIX = 'leat_rl_';

    /**
     * @param CacheInterface $cache
     * @param RemoteAddress $remoteAddress
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly RemoteAddress $remoteAddress
    ) {
    }

    /**
     * Count one attempt and tell the caller whether it may go ahead. Fixed window: blocked attempts do not extend it.
     * ponytail: cache read-then-write is not atomic, so a burst of parallel requests can overshoot the limit a little,
     * and a cache flush resets all buckets. Move to Redis INCR if that ever matters.
     *
     * @param string $key Usage of the limiter, e.g. "referral_subscribe"
     * @param int $maxAttempts Attempts allowed per window
     * @param int $windowSeconds Window length
     * @param int|null $customerId Customer id, null/0 for guests
     * @return bool True when allowed, false when the limit is reached
     */
    public function attempt(string $key, int $maxAttempts, int $windowSeconds, ?int $customerId = null): bool
    {
        $id = self::CACHE_PREFIX . sha1(implode('|', [
            $key,
            (int)$customerId,
            (string)$this->remoteAddress->getRemoteAddress(),
        ]));
        $now = time();

        $stored = (string)$this->cache->load($id);
        [$count, $expiresAt] = $stored === '' ? [0, 0] : array_map('intval', explode(':', $stored));
        if ($expiresAt <= $now) {
            [$count, $expiresAt] = [0, $now + $windowSeconds];
        }

        if ($count >= $maxAttempts) {
            return false;
        }

        $this->cache->save(($count + 1) . ':' . $expiresAt, $id, [], max(1, $expiresAt - $now));

        return true;
    }
}
