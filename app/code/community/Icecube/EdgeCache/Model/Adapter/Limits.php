<?php

/**
 * What a CDN accepts in its tag header and in one purge request. Zero means no limit.
 *
 * SPDX-FileCopyrightText: 2026 Icecube
 * SPDX-License-Identifier: OSL-3.0
 * @package Icecube_EdgeCache
 */

declare(strict_types=1);

final readonly class Icecube_EdgeCache_Model_Adapter_Limits
{
    public function __construct(
        public string $tagSeparator = ',',
        public int $maxTagHeaderBytes = 0,
        public int $maxTagsPerObject = 0,
        public int $maxTagLength = 0,
        public int $tagsPerPurge = 100,
        public int $urlsPerPurge = 100,
    ) {}
}
