<?php

/**
 * Remembers which entities a cacheable page was rendered from.
 *
 * SPDX-FileCopyrightText: 2026 Icecube
 * SPDX-License-Identifier: OSL-3.0
 * @package Icecube_EdgeCache
 */

declare(strict_types=1);

class Icecube_EdgeCache_Model_TagCollector
{
    /** @var array<string, true> */
    private array $tags = [];

    public function collectModel(Mage_Core_Model_Abstract $model): void
    {
        // Most pages load the store's root category, and every product save purges it as an
        // anchor, so tagging it would purge the whole site on each save.
        if ($model instanceof Mage_Catalog_Model_Category && (int) $model->getLevel() < 2) {
            return;
        }

        $this->add($model->getCacheIdTags());
    }

    /**
     * @param iterable<mixed> $items
     */
    public function collectItems(iterable $items): void
    {
        foreach ($items as $item) {
            if ($item instanceof Mage_Core_Model_Abstract) {
                $this->collectModel($item);
            }
        }
    }

    /**
     * A listing whose items load without an event still depends on every entity of its type.
     */
    public function collectType(Mage_Core_Model_Abstract $emptyItem): void
    {
        $tags = $emptyItem->getCacheTags();
        if (is_array($tags)) {
            $this->add(array_filter($tags, fn(mixed $tag): bool => is_string($tag) && !preg_match('/_\d+$/', $tag)));
        }
    }

    /**
     * A block served from block_html loads no models, so its stored cache tags are the only
     * record of what it shows. Core adds the model's id tags to CMS blocks and widgets as they
     * render, so those come back specific. A type tag with no id of its type next to it (the
     * top menu's catalog_category) means the block depends on the whole type, and becomes the
     * coarse tag. Next to an id tag it's only the block's own default and is left out.
     *
     * @param array<mixed> $blockTags
     */
    public function collectBlock(array $blockTags): void
    {
        $tags = array_values(array_filter($blockTags, is_string(...)));
        $specificTypes = [];
        foreach ($tags as $tag) {
            if (preg_match('/^(.+)_\d+$/', $tag, $match) === 1) {
                $specificTypes[$match[1]] = true;
            }
        }

        $this->add(array_filter($tags, fn(string $tag): bool => !isset($specificTypes[$tag])));
    }

    /**
     * @return list<string>
     */
    public function getRawTags(): array
    {
        return array_keys($this->tags);
    }

    /**
     * @param mixed $tags whatever getCacheIdTags() returned, which may be false
     */
    private function add(mixed $tags): void
    {
        if (!is_array($tags)) {
            return;
        }
        foreach ($tags as $tag) {
            if (is_string($tag)) {
                $this->tags[$tag] = true;
            }
        }
    }
}
