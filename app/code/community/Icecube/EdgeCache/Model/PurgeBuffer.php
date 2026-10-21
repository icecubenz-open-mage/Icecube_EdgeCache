<?php

/**
 * Gathers purges for the whole process and queues them once, so a mass update
 * sends a handful of purge calls instead of one per saved entity.
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

    private bool $flushScheduled = false;

    /**
     * @param iterable<mixed> $cacheTags Maho cache tags, as passed to Mage_Core_Model_App::cleanCache()
     */
    public function addCacheTags(iterable $cacheTags): void
    {
        $normaliser = Mage::helper('edgecache')->getTagNormaliser();
        $tags = $normaliser->withCoarse($normaliser->normalise($cacheTags));
        if ($tags === []) {
            return;
        }

        foreach ($tags as $tag) {
            $this->tags[$tag] = true;
        }
        $this->scheduleFlush();
    }

    public function addAll(): void
    {
        $this->all = true;
        $this->scheduleFlush();
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

    public function flush(): void
    {
        $tags = $this->getTags();
        $all = $this->all;
        $this->tags = [];
        $this->all = false;

        if ($all) {
            \Maho\Queue\QueueManager::dispatch(new Icecube_EdgeCache_Model_PurgeAllMessage(), queue: self::QUEUE, dedupeKey: self::PURGE_ALL_DEDUPE_KEY);
            return;
        }
        if ($tags === []) {
            return;
        }

        $chunkSize = max(1, Mage::helper('edgecache')->getAdapter()->getLimits()->tagsPerPurge);
        foreach (array_chunk($tags, $chunkSize) as $chunk) {
            \Maho\Queue\QueueManager::dispatch(new Icecube_EdgeCache_Model_PurgeTagsMessage($chunk), queue: self::QUEUE);
        }
    }

    /**
     * Web requests flush after the response is sent; CLI, cron and queue workers have no
     * such event, so the end of the process is the fallback for every caller.
     */
    private function scheduleFlush(): void
    {
        if ($this->flushScheduled) {
            return;
        }
        $this->flushScheduled = true;
        register_shutdown_function(function (): void {
            try {
                $this->flush();
            } catch (Throwable $e) {
                Mage::logException($e);
            }
        });
    }
}
