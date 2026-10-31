<?php

/**
 * Tells edgecache.js where to fetch the form key, on a page rendered without a session.
 *
 * SPDX-FileCopyrightText: 2026 Icecube
 * SPDX-License-Identifier: OSL-3.0
 * @package Icecube_EdgeCache
 */

declare(strict_types=1);

class Icecube_EdgeCache_Block_FormKey extends Mage_Core_Block_Abstract
{
    #[\Override]
    protected function _toHtml(): string
    {
        return sprintf(
            '<script>var EDGECACHE_FORM_KEY_URL = %s;</script>',
            json_encode($this->getUrl(Icecube_EdgeCache_Helper_Data::FORM_KEY_ROUTE), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }
}
