<?php

/**
 * Reads and claims the edgecache rows in Maho's queue table that no worker has claimed yet.
 *
 * SPDX-FileCopyrightText: 2026 Icecube
 * SPDX-License-Identifier: OSL-3.0
 * @package Icecube_EdgeCache
 */

declare(strict_types=1);

use Maho\Queue\QueueManager;
use Maho\Queue\Transport\DbTransport;

class Icecube_EdgeCache_Model_PendingPurges
{
    /** How many waiting rows one handler looks at when merging */
    private const MERGE_SCAN_LIMIT = 100;

    /**
     * Maho's own dedupe also counts a claimed row, but a claimed purge-all may already have
     * called the CDN, and a change made since then needs one of its own.
     */
    public function hasPurgeAll(): bool
    {
        $id = $this->getConnection()->fetchOne(
            $this->selectPending(Icecube_EdgeCache_Model_PurgeAllMessage::class, 'message_id')->limit(1),
        );

        return $id !== false && $id !== null;
    }

    /**
     * Claims waiting tag purges, oldest first, while their tags still fit in one call.
     *
     * Rows that aren't due yet count too: the dispatch delay exists to batch, and purging
     * early is harmless.
     *
     * @param list<string> $tags the tags already going into the call
     * @return array{tags: list<string>, ids: list<int>, attempt: int}
     */
    public function claimTagPurges(array $tags, int $limit, int $attempt): array
    {
        $merged = array_fill_keys($tags, true);
        $ids = [];
        $rows = $this->getConnection()->fetchAll(
            $this->selectPending(Icecube_EdgeCache_Model_PurgeTagsMessage::class, ['message_id', 'body'])
                ->order('message_id ASC')
                ->limit(self::MERGE_SCAN_LIMIT),
        );
        foreach ($rows as $row) {
            if (!is_array($row) || !is_numeric($row['message_id'] ?? null) || !is_string($row['body'] ?? null)) {
                continue;
            }
            $message = $this->decode($row['body']);
            if ($message === null) {
                continue;
            }
            $candidate = $merged + array_fill_keys($message->tags, true);
            if (count($candidate) > $limit) {
                break;
            }
            $id = (int) $row['message_id'];
            if (!$this->claim($id)) {
                continue;
            }
            $merged = $candidate;
            $ids[] = $id;
            $attempt = max($attempt, $message->attempt);
        }

        return ['tags' => array_map(strval(...), array_keys($merged)), 'ids' => $ids, 'attempt' => $attempt];
    }

    /**
     * @param list<int> $ids rows this handler claimed
     */
    public function delete(array $ids): void
    {
        if ($ids !== []) {
            $this->getConnection()->delete($this->getTable(), ['message_id IN (?)' => $ids]);
        }
    }

    /**
     * Hands claimed rows back to the queue untouched.
     *
     * @param list<int> $ids
     */
    public function release(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $this->getConnection()->update($this->getTable(), [
            'status' => DbTransport::STATUS_PENDING,
            'claimed_at' => null,
            'claim_token' => null,
        ], ['message_id IN (?)' => $ids]);
    }

    public function getLastTagPurgeId(): int
    {
        $id = $this->getConnection()->fetchOne(
            $this->selectPending(Icecube_EdgeCache_Model_PurgeTagsMessage::class, new Maho\Db\Expr('MAX(message_id)')),
        );

        return is_numeric($id) ? (int) $id : 0;
    }

    /**
     * Drops the waiting tag purges a purge-all has covered. Only those queued before it called
     * the CDN: a later one may be for a change the CDN fetched before it was saved.
     */
    public function deleteTagPurgesUpTo(int $lastId): void
    {
        $this->getConnection()->delete($this->getTable(), [
            'queue = ?' => Icecube_EdgeCache_Model_PurgeBuffer::QUEUE,
            'status = ?' => DbTransport::STATUS_PENDING,
            'message_class = ?' => Icecube_EdgeCache_Model_PurgeTagsMessage::class,
            'message_id <= ?' => $lastId,
        ]);
    }

    private function claim(int $id): bool
    {
        $now = Mage_Core_Model_Locale::nowUtc();

        return $this->getConnection()->update($this->getTable(), [
            'status' => DbTransport::STATUS_PROCESSING,
            'claimed_at' => $now,
            'claim_token' => bin2hex(random_bytes(16)),
            'updated_at' => $now,
        ], [
            'message_id = ?' => $id,
            'status = ?' => DbTransport::STATUS_PENDING,
        ]) === 1;
    }

    private function decode(string $body): ?Icecube_EdgeCache_Model_PurgeTagsMessage
    {
        try {
            $message = QueueManager::serializer()->decode([
                'body' => $body,
                'headers' => ['type' => Icecube_EdgeCache_Model_PurgeTagsMessage::class],
            ])->getMessage();
        } catch (Throwable) {
            // Left for the worker, which parks an undecodable row as failed.
            return null;
        }

        return $message instanceof Icecube_EdgeCache_Model_PurgeTagsMessage ? $message : null;
    }

    /**
     * @param string|list<string>|Maho\Db\Expr $columns
     */
    private function selectPending(string $messageClass, string|array|Maho\Db\Expr $columns): Maho\Db\Select
    {
        return $this->getConnection()->select()
            ->from($this->getTable(), $columns)
            ->where('queue = ?', Icecube_EdgeCache_Model_PurgeBuffer::QUEUE)
            ->where('status = ?', DbTransport::STATUS_PENDING)
            ->where('message_class = ?', $messageClass);
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
