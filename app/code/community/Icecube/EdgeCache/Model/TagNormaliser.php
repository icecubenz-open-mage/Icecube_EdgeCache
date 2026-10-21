<?php

/**
 * Turns Maho cache tags into short edge tags and fits them into an adapter's limits.
 *
 * catalog_product_12 becomes p12. A bare type tag (catalog_product) becomes the coarse
 * tag p_all. A page carries it when it shows something that depends on the whole type
 * (the top menu, a listing whose items load without an event, tags over the adapter's
 * limit), and it's purged when core cleans the bare tag, which every model save does.
 * Any other Maho tag (config, layout, block html) has no meaning at the edge and is dropped.
 *
 * SPDX-FileCopyrightText: 2026 Icecube
 * SPDX-License-Identifier: OSL-3.0
 * @package Icecube_EdgeCache
 */

declare(strict_types=1);

final class Icecube_EdgeCache_Model_TagNormaliser
{
    public const TYPES = [
        'catalog_product' => 'p',
        'catalog_category' => 'c',
        'cms_page' => 'cp',
        'cms_block' => 'cb',
        'blog_post' => 'bp',
        'blog_category' => 'bc',
    ];

    private const COARSE_SUFFIX = '_all';

    private readonly string $prefix;

    public function __construct(string $prefix = '')
    {
        $this->prefix = (string) preg_replace('/[^a-z0-9_.-]/', '', strtolower($prefix));
    }

    /**
     * @param iterable<mixed> $tags
     * @return list<string>
     */
    public function normalise(iterable $tags): array
    {
        $pattern = '/^(' . implode('|', array_keys(self::TYPES)) . ')(?:_(\d+))?$/';
        $normalised = [];
        foreach ($tags as $tag) {
            if (!is_string($tag) || preg_match($pattern, strtolower(trim($tag)), $match) !== 1) {
                continue;
            }
            $short = self::TYPES[$match[1]];
            $edgeTag = isset($match[2]) ? $this->prefix . $short . $match[2] : $this->coarse($short);
            $normalised[$edgeTag] = true;
        }

        return array_keys($normalised);
    }

    /**
     * Keeps tags in the order they were collected (the page's own entity comes first)
     * and swaps whatever does not fit for the coarse tag of its type. The limits must leave
     * room for the coarse tags on their own, a few dozen bytes, which every real CDN does.
     *
     * @param list<string> $tags normalised
     * @return list<string>
     */
    public function cap(array $tags, Icecube_EdgeCache_Model_Adapter_Limits $limits): array
    {
        if ($limits->maxTagLength > 0) {
            $tags = array_values(array_filter($tags, fn(string $tag): bool => strlen($tag) <= $limits->maxTagLength));
        }
        if ($this->fits($tags, $limits)) {
            return $tags;
        }

        $reserve = [];
        foreach ($tags as $tag) {
            $reserve[$this->coarse($this->typeOf($tag))] = true;
        }
        $reserve = array_keys($reserve);

        $kept = [];
        $overflowed = [];
        foreach ($tags as $tag) {
            $coarse = $this->coarse($this->typeOf($tag));
            if ($tag !== $coarse && $this->fits([...$kept, $tag, ...$reserve], $limits)) {
                $kept[] = $tag;
            } else {
                $overflowed[$coarse] = true;
            }
        }

        return [...$kept, ...array_keys($overflowed)];
    }

    /**
     * @param list<string> $tags
     */
    private function fits(array $tags, Icecube_EdgeCache_Model_Adapter_Limits $limits): bool
    {
        if ($limits->maxTagsPerObject > 0 && count($tags) > $limits->maxTagsPerObject) {
            return false;
        }

        return $limits->maxTagHeaderBytes <= 0
            || strlen(implode($limits->tagSeparator, $tags)) <= $limits->maxTagHeaderBytes;
    }

    private function typeOf(string $tag): string
    {
        $unprefixed = substr($tag, strlen($this->prefix));
        if (str_ends_with($unprefixed, self::COARSE_SUFFIX)) {
            return substr($unprefixed, 0, -strlen(self::COARSE_SUFFIX));
        }

        return rtrim($unprefixed, '0123456789');
    }

    private function coarse(string $type): string
    {
        return $this->prefix . $type . self::COARSE_SUFFIX;
    }
}
