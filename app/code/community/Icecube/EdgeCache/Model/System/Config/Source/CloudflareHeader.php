<?php

/**
 * SPDX-FileCopyrightText: 2026 Icecube
 * SPDX-License-Identifier: OSL-3.0
 * @package Icecube_EdgeCache
 */

declare(strict_types=1);

class Icecube_EdgeCache_Model_System_Config_Source_CloudflareHeader
{
    /**
     * @return list<array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'CDN-Cache-Control', 'label' => 'CDN-Cache-Control'],
            ['value' => 'Cloudflare-CDN-Cache-Control', 'label' => 'Cloudflare-CDN-Cache-Control'],
        ];
    }
}
