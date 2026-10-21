<?php

declare(strict_types=1);

namespace Icecube\EdgeCache\Tests\Support;

use Icecube_EdgeCache_Model_Adapter_AdapterInterface;
use Icecube_EdgeCache_Model_Adapter_Limits;
use Icecube_EdgeCache_Model_Adapter_RateLimitedException;
use Icecube_EdgeCache_Model_Ttl;
use Mage;
use RuntimeException;

/**
 * Stands in for a CDN and remembers every purge it was asked for.
 */
final class RecordingAdapter implements Icecube_EdgeCache_Model_Adapter_AdapterInterface
{
    public const CODE = 'recording';

    /** @var list<array{string, list<string>}> */
    public static array $calls = [];

    /** Makes every purge fail the way an unreachable or rate-limited CDN does */
    public static bool $failing = false;

    /** With $failing, fails the way a 429 carrying Retry-After does */
    public static ?int $retryAfter = null;

    /**
     * Registers the adapter the way a third-party module would, then selects it for the current store.
     */
    public static function install(): void
    {
        self::$calls = [];
        self::$failing = false;
        self::$retryAfter = null;
        Mage::app()->getConfig()->setNode('global/edgecache/adapters/' . self::CODE . '/class', self::class);
        Mage::app()->getStore()?->setConfig('edgecache/general/adapter', self::CODE);
    }

    #[\Override]
    public function getLimits(): Icecube_EdgeCache_Model_Adapter_Limits
    {
        return new Icecube_EdgeCache_Model_Adapter_Limits(tagsPerPurge: 2);
    }

    #[\Override]
    public function getCacheableHeaders(Icecube_EdgeCache_Model_Ttl $ttl, array $tags): array
    {
        return ['X-Recorded-Tags' => implode(',', $tags)];
    }

    #[\Override]
    public function getUncacheableHeaders(): array
    {
        return [];
    }

    #[\Override]
    public function purgeTags(array $tags): void
    {
        self::failIfFailing();
        self::$calls[] = ['purgeTags', $tags];
    }

    #[\Override]
    public function purgeUrls(array $urls): void
    {
        self::$calls[] = ['purgeUrls', $urls];
    }

    #[\Override]
    public function purgeAll(): void
    {
        self::failIfFailing();
        self::$calls[] = ['purgeAll', []];
    }

    private static function failIfFailing(): void
    {
        if (self::$failing && self::$retryAfter !== null) {
            throw new Icecube_EdgeCache_Model_Adapter_RateLimitedException('CDN rate limit', self::$retryAfter);
        }
        if (self::$failing) {
            throw new RuntimeException('CDN rate limit');
        }
    }
}
