<?php

/**
 * Cloudflare: CDN-Cache-Control for the edge TTL, Cache-Tag for tags, the zone purge API.
 *
 * HTML is only cached once a Cache Rule makes it eligible; see README.md.
 *
 * SPDX-FileCopyrightText: 2026 Icecube
 * SPDX-License-Identifier: OSL-3.0
 * @package Icecube_EdgeCache
 */

declare(strict_types=1);

class Icecube_EdgeCache_Model_Adapter_Cloudflare extends Icecube_EdgeCache_Model_Adapter_AbstractHttp
{
    public const XML_PATH_ZONE_ID = 'edgecache/cloudflare/zone_id';
    public const XML_PATH_API_TOKEN = 'edgecache/cloudflare/api_token';
    public const XML_PATH_HEADER = 'edgecache/cloudflare/header';

    public const API_URL = 'https://api.cloudflare.com/client/v4/zones/%s/purge_cache';
    public const TAG_HEADER = 'Cache-Tag';

    /** Proxied on to any CDN behind Cloudflare; the Cloudflare- variant is not */
    private const TTL_HEADERS = ['CDN-Cache-Control', 'Cloudflare-CDN-Cache-Control'];

    #[\Override]
    public function getLimits(): Icecube_EdgeCache_Model_Adapter_Limits
    {
        // 16 KB of Cache-Tag after the header name; one purge call takes 100 tags or URLs
        return new Icecube_EdgeCache_Model_Adapter_Limits(
            tagSeparator: ',',
            maxTagHeaderBytes: 16 * 1024,
            maxTagLength: 1024,
            tagsPerPurge: 100,
            urlsPerPurge: 100,
        );
    }

    #[\Override]
    public function getCacheableHeaders(Icecube_EdgeCache_Model_Ttl $ttl, array $tags): array
    {
        // s-maxage would turn off stale-while-revalidate at Cloudflare, so the TTL only goes here
        $headers = [$this->getTtlHeader() => $ttl->toDirectives()];
        if ($tags !== []) {
            $headers[self::TAG_HEADER] = implode(',', $tags);
        }

        return $headers;
    }

    #[\Override]
    public function getUncacheableHeaders(): array
    {
        return [$this->getTtlHeader() => 'no-store'];
    }

    #[\Override]
    public function purgeTags(array $tags): void
    {
        if ($tags !== []) {
            $this->purge(['tags' => $tags]);
        }
    }

    #[\Override]
    public function purgeUrls(array $urls): void
    {
        if ($urls !== []) {
            $this->purge(['files' => $urls]);
        }
    }

    #[\Override]
    public function purgeAll(): void
    {
        $this->purge(['purge_everything' => true]);
    }

    /**
     * Cloudflare takes one purge type per call.
     *
     * @param array<string, mixed> $body
     */
    private function purge(array $body): void
    {
        $zoneId = $this->getConfig(self::XML_PATH_ZONE_ID);
        $token = Mage::helper('core')->decrypt($this->getConfig(self::XML_PATH_API_TOKEN));
        if ($zoneId === '' || !is_string($token) || $token === '') {
            throw new RuntimeException('Cloudflare purge needs a zone ID and an API token');
        }

        $response = $this->getHttpClient()->request('POST', sprintf(self::API_URL, rawurlencode($zoneId)), [
            'auth_bearer' => $token,
            'json' => $body,
        ]);
        $this->assertSuccessful($response, 'Cloudflare purge');

        $result = $response->toArray(false);
        if (($result['success'] ?? false) !== true) {
            throw new RuntimeException('Cloudflare purge was not accepted: ' . json_encode($result['errors'] ?? $result));
        }
    }

    private function getTtlHeader(): string
    {
        $header = $this->getConfig(self::XML_PATH_HEADER);

        return in_array($header, self::TTL_HEADERS, true) ? $header : self::TTL_HEADERS[0];
    }
}
