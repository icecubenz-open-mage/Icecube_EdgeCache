<?php

/**
 * A CDN that can mark its whole cache stale instead of emptying it.
 *
 * The next request for a stale page revalidates with the origin. With
 * stale-while-revalidate, the visitor gets the stale copy while the CDN fetches
 * the new one, so a release that changes every page leaves no edge cold. Use it
 * when the old pages may be served once more, never for a change to a price,
 * stock or content that must show at once: those stay purges.
 *
 * SPDX-FileCopyrightText: 2026 Icecube
 * SPDX-License-Identifier: OSL-3.0
 * @package Icecube_EdgeCache
 */

declare(strict_types=1);

interface Icecube_EdgeCache_Model_Adapter_InvalidatesInterface
{
    /**
     * Throws when the CDN rejects or rate limits the call, as the purges do.
     */
    public function invalidateAll(): void;
}
