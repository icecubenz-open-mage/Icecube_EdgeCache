<?php

declare(strict_types=1);

namespace Icecube\EdgeCache\Tests\Support;

use Icecube_EdgeCache_Model_Adapter_AdapterInterface;
use Icecube_EdgeCache_Model_Adapter_Limits;
use Icecube_EdgeCache_Model_Ttl;
use Mage;

/**
 * Stands in for a CDN and remembers every purge it was asked for.
 */
final class RecordingAdapter implements Icecube_EdgeCache_Model_Adapter_AdapterInterface
{
    public const CODE = 'recording';

    /** @var list<array{string, list<string>}> */
    public static array $calls = [];

    /**
     * Registers the adapter the way a third-party module would, then selects it for the current store.
     */
    public static function install(): void
    {
        self::$calls = [];
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
        self::$calls[] = ['purgeAll', []];
    }
}
