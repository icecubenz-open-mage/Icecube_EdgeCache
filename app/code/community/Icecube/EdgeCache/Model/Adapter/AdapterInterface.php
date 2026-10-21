<?php

/**
 * One CDN or reverse proxy: the headers it reads and the purge API it offers.
 *
 * SPDX-FileCopyrightText: 2026 Icecube
 * SPDX-License-Identifier: OSL-3.0
 * @package Icecube_EdgeCache
 */

declare(strict_types=1);

interface Icecube_EdgeCache_Model_Adapter_AdapterInterface
{
    /**
     * Applied by the core before tags reach a header or a purge call.
     */
    public function getLimits(): Icecube_EdgeCache_Model_Adapter_Limits;

    /**
     * Sent after the browser Cache-Control, so an adapter may replace it when its CDN reads nothing else.
     *
     * @param list<string> $tags normalised and capped to getLimits()
     * @return array<string, string>
     */
    public function getCacheableHeaders(Icecube_EdgeCache_Model_Ttl $ttl, array $tags): array;

    /**
     * For CDNs that would otherwise cache a response marked private.
     *
     * @return array<string, string>
     */
    public function getUncacheableHeaders(): array;

    /**
     * Throws when the CDN rejects or rate limits the call, so the queue retries it.
     *
     * @param list<string> $tags at most getLimits()->tagsPerPurge
     */
    public function purgeTags(array $tags): void;

    /**
     * @param list<string> $urls absolute URLs, at most getLimits()->urlsPerPurge
     */
    public function purgeUrls(array $urls): void;

    public function purgeAll(): void;
}
