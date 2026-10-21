<?php

/**
 * Queue message asking the configured adapter to purge these edge tags.
 *
 * SPDX-FileCopyrightText: 2026 Icecube
 * SPDX-License-Identifier: OSL-3.0
 * @package Icecube_EdgeCache
 */

declare(strict_types=1);

final readonly class Icecube_EdgeCache_Model_PurgeTagsMessage
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public array $tags,
    ) {}
}
