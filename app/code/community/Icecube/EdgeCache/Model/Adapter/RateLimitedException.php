<?php

/**
 * A purge the CDN refused for now, carrying how long it asked us to wait.
 *
 * SPDX-FileCopyrightText: 2026 Icecube
 * SPDX-License-Identifier: OSL-3.0
 * @package Icecube_EdgeCache
 */

declare(strict_types=1);

class Icecube_EdgeCache_Model_Adapter_RateLimitedException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $retryAfter,
    ) {
        parent::__construct($message);
    }
}
