<?php

/**
 * The adapters registered under global/edgecache/adapters.
 *
 * SPDX-FileCopyrightText: 2026 Icecube
 * SPDX-License-Identifier: OSL-3.0
 * @package Icecube_EdgeCache
 */

declare(strict_types=1);

class Icecube_EdgeCache_Model_System_Config_Source_Adapter
{
    /**
     * @return list<array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $options = [];
        $adapters = Mage::app()->getConfig()->getNode(Icecube_EdgeCache_Helper_Data::XML_PATH_ADAPTERS);
        if ($adapters === false) {
            return $options;
        }

        foreach ($adapters->children() as $code => $adapter) {
            $options[] = [
                'value' => (string) $code,
                'label' => Mage::helper('edgecache')->__((string) $adapter->label),
            ];
        }

        return $options;
    }
}
