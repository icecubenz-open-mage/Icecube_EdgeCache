<?php

/**
 * For development and tests: visible headers that no CDN strips, and purges written to a log.
 *
 * SPDX-FileCopyrightText: 2026 Icecube
 * SPDX-License-Identifier: OSL-3.0
 * @package Icecube_EdgeCache
 */

declare(strict_types=1);

class Icecube_EdgeCache_Model_Adapter_Noop implements Icecube_EdgeCache_Model_Adapter_AdapterInterface
{
    public const TTL_HEADER = 'X-Edge-Cache-Control';
    public const TAG_HEADER = 'X-Edge-Tags';
    public const LOG_FILE = 'edgecache.log';
    public const XML_PATH_LOG_PURGES = 'edgecache/noop/log_purges';

    #[\Override]
    public function getLimits(): Icecube_EdgeCache_Model_Adapter_Limits
    {
        return new Icecube_EdgeCache_Model_Adapter_Limits(tagSeparator: ',', maxTagHeaderBytes: 16 * 1024);
    }

    #[\Override]
    public function getCacheableHeaders(Icecube_EdgeCache_Model_Ttl $ttl, array $tags): array
    {
        $headers = [self::TTL_HEADER => $ttl->toDirectives()];
        if ($tags !== []) {
            $headers[self::TAG_HEADER] = implode(',', $tags);
        }

        return $headers;
    }

    #[\Override]
    public function getUncacheableHeaders(): array
    {
        return [self::TTL_HEADER => 'no-store'];
    }

    #[\Override]
    public function purgeTags(array $tags): void
    {
        $this->log('purge tags: ' . implode(',', $tags));
    }

    #[\Override]
    public function purgeUrls(array $urls): void
    {
        $this->log('purge urls: ' . implode(' ', $urls));
    }

    #[\Override]
    public function purgeAll(): void
    {
        $this->log('purge all');
    }

    private function log(string $message): void
    {
        if (Mage::getStoreConfigFlag(self::XML_PATH_LOG_PURGES)) {
            Mage::log($message, Mage::LOG_INFO, self::LOG_FILE, true);
        }
    }
}
