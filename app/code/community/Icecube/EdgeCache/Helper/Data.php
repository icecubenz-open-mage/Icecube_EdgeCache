<?php

/**
 * Store config for the edge cache.
 *
 * SPDX-FileCopyrightText: 2026 Icecube
 * SPDX-License-Identifier: OSL-3.0
 * @package Icecube_EdgeCache
 */

declare(strict_types=1);

class Icecube_EdgeCache_Helper_Data extends Mage_Core_Helper_Abstract
{
    public const XML_PATH_ENABLED = 'edgecache/general/enabled';
    public const XML_PATH_ADAPTER = 'edgecache/general/adapter';
    public const XML_PATH_CACHEABLE_ACTIONS = 'edgecache/general/cacheable_actions';
    public const XML_PATH_BYPASS_COOKIES = 'edgecache/general/bypass_cookies';
    public const XML_PATH_SHORT_TTL_ACTIONS = 'edgecache/general/short_ttl_actions';
    public const XML_PATH_TTL = 'edgecache/general/ttl';
    public const XML_PATH_STALE_WHILE_REVALIDATE = 'edgecache/general/stale_while_revalidate';
    public const XML_PATH_STALE_IF_ERROR = 'edgecache/general/stale_if_error';
    public const XML_PATH_SHORT_TTL = 'edgecache/general/short_ttl';
    public const XML_PATH_TAG_PREFIX = 'edgecache/general/tag_prefix';
    public const XML_PATH_PURGE_DELAY = 'edgecache/general/purge_delay';
    public const XML_PATH_ADAPTERS = 'global/edgecache/adapters';

    /**
     * Core's form key endpoint, Mage_Core_Model_Session::FORM_KEY_ROUTE as a full action name,
     * written out because a Maho without the fallback has no such constant. It hands the
     * visitor a key and must start their session, so it never skips it, listed or not.
     */
    public const FORM_KEY_ACTION = 'core_index_formKey';

    /** Mage_Core_Model_Design_Package::_checkUserAgentAgainstRegexps() reads these */
    public const XML_PATHS_DESIGN_UA_REGEXP = [
        'design/package/ua_regexp',
        'design/theme/locale_ua_regexp',
        'design/theme/template_ua_regexp',
        'design/theme/skin_ua_regexp',
        'design/theme/layout_ua_regexp',
        'design/theme/default_ua_regexp',
    ];

    public function isEnabled(): bool
    {
        return Mage::getStoreConfigFlag(self::XML_PATH_ENABLED);
    }

    /**
     * For purges, which run in admin, cron and workers rather than in the store that serves
     * the pages, so the current store says nothing about whether any page is cached.
     */
    public function isEnabledForAnyStore(): bool
    {
        return array_any(Mage::app()->getStores(), fn(Mage_Core_Model_Store $store): bool => Mage::getStoreConfigFlag(self::XML_PATH_ENABLED, $store));
    }

    public function getAdapterCode(): string
    {
        return $this->getString(self::XML_PATH_ADAPTER);
    }

    public function getAdapter(): Icecube_EdgeCache_Model_Adapter_AdapterInterface
    {
        $code = $this->getAdapterCode();
        $class = Mage::app()->getConfig()->getNode(self::XML_PATH_ADAPTERS . '/' . $code . '/class');
        $adapter = $class === false ? null : Mage::getModel(trim((string) $class));
        if (!$adapter instanceof Icecube_EdgeCache_Model_Adapter_AdapterInterface) {
            throw new RuntimeException(sprintf('Edge cache adapter "%s" is not registered under %s', $code, self::XML_PATH_ADAPTERS));
        }

        return $adapter;
    }

    public function getTagNormaliser(): Icecube_EdgeCache_Model_TagNormaliser
    {
        return new Icecube_EdgeCache_Model_TagNormaliser($this->getTagPrefix());
    }

    public function isCacheableAction(string $fullActionName): bool
    {
        return $fullActionName !== self::FORM_KEY_ACTION
            && in_array($fullActionName, $this->getList(self::XML_PATH_CACHEABLE_ACTIONS), true);
    }

    /**
     * Every cookie whose presence means the page may differ from the shared copy. The session
     * cookies are always in it (legacy names too, since the session migrates them on start),
     * so clearing the setting can't make sessions cacheable.
     *
     * @return list<string>
     */
    public function getBypassCookies(): array
    {
        return array_values(array_unique([
            Mage_Core_Controller_Front_Action::SESSION_NAMESPACE,
            ...Mage_Core_Controller_Front_Action::SESSION_LEGACY_NAMESPACES,
            ...$this->getList(self::XML_PATH_BYPASS_COOKIES),
        ]));
    }

    /**
     * Core sends a page rendered without a session with an empty form key, and its js.js
     * fetches a real one from FORM_KEY_ROUTE before the first POST. On a Maho without that,
     * the page would hold a key no session knows, so every form on it would fail.
     */
    public function hasCoreFormKeyFallback(): bool
    {
        return defined('Mage_Core_Model_Session::FORM_KEY_ROUTE');
    }

    /**
     * A design exception picks the theme from the User-Agent, which the edge doesn't key on.
     */
    public function hasDesignUserAgentExceptions(): bool
    {
        foreach (self::XML_PATHS_DESIGN_UA_REGEXP as $path) {
            $value = $this->getString($path);
            if ($value === '') {
                continue;
            }
            try {
                if (Mage::helper('core/unserializeArray')->unserialize($value) !== []) {
                    return true;
                }
            } catch (Exception) {
                // Core ignores a value it can't read, and so renders no exception for it
            }
        }

        return false;
    }

    public function getTtl(string $fullActionName): Icecube_EdgeCache_Model_Ttl
    {
        if (in_array($fullActionName, $this->getList(self::XML_PATH_SHORT_TTL_ACTIONS), true)) {
            return $this->getShortTtl();
        }

        return new Icecube_EdgeCache_Model_Ttl(
            $this->getInt(self::XML_PATH_TTL),
            $this->getInt(self::XML_PATH_STALE_WHILE_REVALIDATE),
            $this->getInt(self::XML_PATH_STALE_IF_ERROR),
        );
    }

    /**
     * Search results and 404s change with any catalogue edit but carry no tag that would purge them.
     */
    public function getShortTtl(): Icecube_EdgeCache_Model_Ttl
    {
        $ttl = $this->getInt(self::XML_PATH_SHORT_TTL);

        return new Icecube_EdgeCache_Model_Ttl($ttl, $ttl, $this->getInt(self::XML_PATH_STALE_IF_ERROR));
    }

    /**
     * Seconds a tag purge waits in the queue, so the purges from a burst of saves merge into one call.
     */
    public function getPurgeDelay(): int
    {
        return max(0, $this->getInt(self::XML_PATH_PURGE_DELAY));
    }

    public function getTagPrefix(): string
    {
        return $this->getString(self::XML_PATH_TAG_PREFIX);
    }

    /**
     * @return list<string>
     */
    private function getList(string $path): array
    {
        $items = preg_split('/[\s,]+/', $this->getString($path), -1, PREG_SPLIT_NO_EMPTY);

        return $items === false ? [] : $items;
    }

    private function getString(string $path): string
    {
        $value = Mage::getStoreConfig($path);

        return is_string($value) ? trim($value) : '';
    }

    private function getInt(string $path): int
    {
        return max(0, (int) $this->getString($path));
    }
}
