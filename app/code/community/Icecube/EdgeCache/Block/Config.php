<?php

/**
 * Tells edgecache.js where to fetch the visitor's state.
 *
 * SPDX-FileCopyrightText: 2026 Icecube
 * SPDX-License-Identifier: OSL-3.0
 * @package Icecube_EdgeCache
 */

declare(strict_types=1);

class Icecube_EdgeCache_Block_Config extends Mage_Core_Block_Abstract
{
    #[\Override]
    protected function _toHtml(): string
    {
        if (!Mage::helper('edgecache')->isEnabled()) {
            return '';
        }

        return sprintf(
            '<script>window.EDGECACHE_STATE_URL = %s;</script>',
            json_encode($this->getUrl('edgecache/state'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }
}
