<?php

/**
 * How long the edge may keep a response, in seconds.
 *
 * SPDX-FileCopyrightText: 2026 Icecube
 * SPDX-License-Identifier: OSL-3.0
 * @package Icecube_EdgeCache
 */

declare(strict_types=1);

final readonly class Icecube_EdgeCache_Model_Ttl
{
    public function __construct(
        public int $maxAge,
        public int $staleWhileRevalidate = 0,
        public int $staleIfError = 0,
    ) {}

    /**
     * The directive list shared by every RFC 9213 style header (CDN-Cache-Control and friends).
     */
    public function toDirectives(): string
    {
        $directives = ['max-age=' . $this->maxAge];
        if ($this->staleWhileRevalidate > 0) {
            $directives[] = 'stale-while-revalidate=' . $this->staleWhileRevalidate;
        }
        if ($this->staleIfError > 0) {
            $directives[] = 'stale-if-error=' . $this->staleIfError;
        }

        return implode(', ', $directives);
    }
}
