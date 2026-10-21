<?php

/**
 * Runs queued purges against the adapter configured when the message is handled.
 *
 * SPDX-FileCopyrightText: 2026 Icecube
 * SPDX-License-Identifier: OSL-3.0
 * @package Icecube_EdgeCache
 */

declare(strict_types=1);

class Icecube_EdgeCache_Model_PurgeHandler
{
    #[Maho\Config\MessageHandler]
    public function purgeTags(Icecube_EdgeCache_Model_PurgeTagsMessage $message): void
    {
        Mage::helper('edgecache')->getAdapter()->purgeTags($message->tags);
    }

    #[Maho\Config\MessageHandler]
    public function purgeAll(Icecube_EdgeCache_Model_PurgeAllMessage $message): void
    {
        Mage::helper('edgecache')->getAdapter()->purgeAll();
    }
}
