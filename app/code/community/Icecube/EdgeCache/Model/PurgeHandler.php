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
    public const MAX_ATTEMPTS = 3;

    /** Seconds before the first retry, multiplied by four for each one after */
    private const RETRY_DELAY = 60;

    /**
     * Merges the waiting tag purges into this call, so a burst of saves costs one request
     * against the CDN's rate limit instead of one each.
     *
     * Retries are handled here rather than by the queue, which would retry each message on its
     * own and can't escalate. Tags that keep failing become a purge-all: a stale page is worse
     * than an empty cache.
     */
    #[Maho\Config\MessageHandler]
    public function purgeTags(Icecube_EdgeCache_Model_PurgeTagsMessage $message): void
    {
        $adapter = Mage::helper('edgecache')->getAdapter();
        $pending = Mage::getSingleton('edgecache/pendingPurges');
        $limit = max(1, $adapter->getLimits()->tagsPerPurge, count($message->tags));
        $batch = $pending->claimTagPurges($message->tags, $limit, $message->attempt);

        try {
            $adapter->purgeTags($batch['tags']);
        } catch (Throwable $e) {
            Mage::logException($e);
            $this->retry($batch['tags'], $batch['attempt'], $batch['ids']);

            return;
        }
        $pending->delete($batch['ids']);
    }

    #[Maho\Config\MessageHandler]
    public function purgeAll(Icecube_EdgeCache_Model_PurgeAllMessage $message): void
    {
        $pending = Mage::getSingleton('edgecache/pendingPurges');
        $lastTagPurgeId = $pending->getLastTagPurgeId();

        Mage::helper('edgecache')->getAdapter()->purgeAll();
        $pending->deleteTagPurgesUpTo($lastTagPurgeId);
    }

    /**
     * @param list<string> $tags
     * @param list<int> $claimedIds
     */
    private function retry(array $tags, int $attempt, array $claimedIds): void
    {
        try {
            if ($attempt >= self::MAX_ATTEMPTS) {
                Mage::getSingleton('edgecache/purgeBuffer')->queuePurgeAll();
            } else {
                \Maho\Queue\QueueManager::dispatch(
                    new Icecube_EdgeCache_Model_PurgeTagsMessage($tags, $attempt + 1),
                    self::RETRY_DELAY * 4 ** ($attempt - 1),
                    Icecube_EdgeCache_Model_PurgeBuffer::QUEUE,
                );
            }
        } catch (Throwable $e) {
            // Couldn't queue the retry: let the queue retry this message, and the merged ones on their own.
            Mage::getSingleton('edgecache/pendingPurges')->release($claimedIds);
            throw $e;
        }
        Mage::getSingleton('edgecache/pendingPurges')->delete($claimedIds);
    }
}
