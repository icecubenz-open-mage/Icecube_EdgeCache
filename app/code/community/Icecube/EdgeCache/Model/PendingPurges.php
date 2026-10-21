<?php

/**
 * Reads the edgecache rows in Maho's queue table that no worker has claimed yet.
 *
 * SPDX-FileCopyrightText: 2026 Icecube
 * SPDX-License-Identifier: OSL-3.0
 * @package Icecube_EdgeCache
 */

declare(strict_types=1);

use Maho\Queue\Transport\DbTransport;

class Icecube_EdgeCache_Model_PendingPurges
{
    /**
     * Maho's own dedupe also counts a claimed row, but a claimed purge-all may already have
     * called the CDN, and a change made since then needs one of its own.
     */
    public function hasPurgeAll(): bool
    {
        $connection = $this->getConnection();
        $id = $connection->fetchOne(
            $connection->select()
                ->from($this->getTable(), 'message_id')
                ->where('queue = ?', Icecube_EdgeCache_Model_PurgeBuffer::QUEUE)
                ->where('status = ?', DbTransport::STATUS_PENDING)
                ->where('message_class = ?', Icecube_EdgeCache_Model_PurgeAllMessage::class)
                ->limit(1),
        );

        return $id !== false && $id !== null;
    }

    private function getConnection(): Maho\Db\Adapter\AdapterInterface
    {
        return Mage::getSingleton('core/resource')->getConnection('core_write')
            ?: throw new RuntimeException('No database connection for the queue');
    }

    private function getTable(): string
    {
        return Mage::getSingleton('core/resource')->getTableName('queue/message');
    }
}
