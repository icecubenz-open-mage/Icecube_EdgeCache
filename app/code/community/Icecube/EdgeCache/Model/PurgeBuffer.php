<?php

/**
 * Turns cache cleans into queued purges.
 *
 * In a web request the purges are held and queued once after the response, so a mass
 * update sends a handful of purge calls instead of one per saved entity. Anywhere else
 * (queue workers, cron, CLI) they're queued as they come: a resident worker can run for an
 * hour, and Maho's worker exposes no hook for "message handled" to flush on.
 *
 * SPDX-FileCopyrightText: 2026 Icecube
 * SPDX-License-Identifier: OSL-3.0
 * @package Icecube_EdgeCache
 */

declare(strict_types=1);

class Icecube_EdgeCache_Model_PurgeBuffer
{
    public const QUEUE = 'edgecache';
    public const PURGE_ALL_DEDUPE_KEY = 'edgecache_purge_all';

    /** @var array<string, true> */
    private array $tags = [];

    private bool $all = false;

    private bool $held = false;

    private bool $flushScheduled = false;

    /**
     * @param iterable<mixed> $cacheTags Maho cache tags, as passed to Mage_Core_Model_App::cleanCache()
     */
    public function addCacheTags(iterable $cacheTags): void
    {
        $tags = Mage::helper('edgecache')->getTagNormaliser()->normalise($cacheTags);
        if ($tags === []) {
            return;
        }

        foreach ($tags as $tag) {
            $this->tags[$tag] = true;
        }
        $this->added();
    }

    public function addAll(): void
    {
        $this->all = true;
        $this->added();
    }

    public function holdUntilResponse(): void
    {
        $this->held = true;
    }

    /**
     * @return list<string>
     */
    public function getTags(): array
    {
        return array_keys($this->tags);
    }

    public function isPurgingAll(): bool
    {
        return $this->all;
    }

    /**
     * Forgets a purge only once it's queued, so a failure leaves it for the next flush.
     */
    public function flush(): void
    {
        if ($this->all) {
            $this->queuePurgeAll();
            $this->all = false;
            $this->tags = [];
            return;
        }
        if ($this->tags === []) {
            return;
        }

        $chunkSize = max(1, Mage::helper('edgecache')->getAdapter()->getLimits()->tagsPerPurge);
        $delay = Mage::helper('edgecache')->getPurgeDelay();
        foreach (array_chunk($this->getTags(), $chunkSize) as $chunk) {
            \Maho\Queue\QueueManager::dispatch(new Icecube_EdgeCache_Model_PurgeTagsMessage($chunk), $delay, self::QUEUE);
            foreach ($chunk as $tag) {
                unset($this->tags[$tag]);
            }
        }
    }

    /**
     * Queues a purge-all unless one is already waiting.
     */
    public function queuePurgeAll(): void
    {
        if (Mage::getSingleton('edgecache/pendingPurges')->hasPurgeAll()) {
            return;
        }
        // The key is recorded, not enforced: Maho's check would also count a claimed row.
        \Maho\Queue\QueueManager::dispatch(
            new Icecube_EdgeCache_Model_PurgeAllMessage(),
            queue: self::QUEUE,
            stamps: [new \Maho\Queue\Stamp\DedupeKeyStamp(self::PURGE_ALL_DEDUPE_KEY, enforce: false)],
        );
    }

    /**
     * Never throws: the caller is saving a product or has already sent its response.
     */
    public function flushQuietly(): void
    {
        try {
            $this->flush();
        } catch (Throwable $e) {
            Mage::logException($e);
        }
    }

    private function added(): void
    {
        $this->scheduleFlush();
        if (!$this->held) {
            $this->flushQuietly();
        }
    }

    /**
     * The fallback for a request that never reaches its response event, and the retry for
     * anything a flush failed to queue.
     */
    private function scheduleFlush(): void
    {
        if ($this->flushScheduled) {
            return;
        }
        $this->flushScheduled = true;
        register_shutdown_function($this->flushQuietly(...));
    }
}
