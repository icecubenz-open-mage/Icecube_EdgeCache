<?php

/**
 * Varnish with vmod_xkey: the sample VCL in docs/design/edge-cache.md reads CDN-Cache-Control
 * and stores the xkey header, and purges arrive as PURGE or BAN requests sent to every node.
 *
 * SPDX-FileCopyrightText: 2026 Icecube
 * SPDX-License-Identifier: OSL-3.0
 * @package Icecube_EdgeCache
 */

declare(strict_types=1);

class Icecube_EdgeCache_Model_Adapter_Varnish extends Icecube_EdgeCache_Model_Adapter_AbstractHttp
{
    public const XML_PATH_NODES = 'edgecache/varnish/nodes';
    public const XML_PATH_SOFT_PURGE = 'edgecache/varnish/soft_purge';

    public const TTL_HEADER = 'CDN-Cache-Control';
    public const TAG_HEADER = 'xkey';

    #[\Override]
    public function getLimits(): Icecube_EdgeCache_Model_Adapter_Limits
    {
        // Varnish refuses a response header over http_resp_hdr_len, 8 KB by default
        return new Icecube_EdgeCache_Model_Adapter_Limits(
            tagSeparator: ' ',
            maxTagHeaderBytes: 7 * 1024,
            tagsPerPurge: 100,
            urlsPerPurge: 1,
        );
    }

    #[\Override]
    public function getCacheableHeaders(Icecube_EdgeCache_Model_Ttl $ttl, array $tags): array
    {
        $headers = [self::TTL_HEADER => $ttl->toDirectives()];
        if ($tags !== []) {
            $headers[self::TAG_HEADER] = implode(' ', $tags);
        }

        return $headers;
    }

    /**
     * Core sends a 301 as max-age=86400 with no cookie, and a VCL that falls back to
     * Cache-Control would keep it with no tag to purge it by.
     */
    #[\Override]
    public function getUncacheableHeaders(): array
    {
        return [self::TTL_HEADER => 'no-store'];
    }

    #[\Override]
    public function purgeTags(array $tags): void
    {
        if ($tags === []) {
            return;
        }

        $headers = [self::TAG_HEADER => implode(' ', $tags)];
        if (Mage::getStoreConfigFlag(self::XML_PATH_SOFT_PURGE)) {
            $headers['X-Soft-Purge'] = '1';
        }
        foreach ($this->getNodes() as $node) {
            $this->send('PURGE', $node . '/', $headers);
        }
    }

    #[\Override]
    public function purgeUrls(array $urls): void
    {
        foreach ($urls as $url) {
            $parts = parse_url($url);
            if (!is_array($parts) || !isset($parts['host'])) {
                continue;
            }
            $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
            foreach ($this->getNodes() as $node) {
                $this->send('PURGE', $node . $path, ['Host' => $parts['host']]);
            }
        }
    }

    #[\Override]
    public function purgeAll(): void
    {
        foreach ($this->getStoreHosts() as $host) {
            foreach ($this->getNodes() as $node) {
                $this->send('BAN', $node . '/', ['Host' => $host, 'X-Ban-Url' => '.']);
            }
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private function send(string $method, string $url, array $headers): void
    {
        $response = $this->getHttpClient()->request($method, $url, ['headers' => $headers]);
        $this->assertSuccessful($response, sprintf('Varnish %s %s', $method, $url));
    }

    /**
     * @return list<string>
     */
    private function getNodes(): array
    {
        $nodes = preg_split('/\s+/', $this->getConfig(self::XML_PATH_NODES), -1, PREG_SPLIT_NO_EMPTY);
        if ($nodes === false || $nodes === []) {
            throw new RuntimeException('Varnish purge needs at least one node URL');
        }

        return array_map(fn(string $node): string => rtrim($node, '/'), $nodes);
    }

    /**
     * @return list<string>
     */
    private function getStoreHosts(): array
    {
        $hosts = [];
        foreach (Mage::app()->getStores() as $store) {
            foreach ([false, true] as $secure) {
                $host = parse_url($store->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB, $secure), PHP_URL_HOST);
                if (is_string($host)) {
                    $hosts[$host] = true;
                }
            }
        }

        return array_keys($hosts);
    }
}
