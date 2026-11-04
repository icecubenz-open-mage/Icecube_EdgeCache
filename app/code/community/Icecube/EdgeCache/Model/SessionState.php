<?php

/**
 * Tells whether the session data of this request holds something the visitor would miss.
 *
 * SPDX-FileCopyrightText: 2026 Icecube
 * SPDX-License-Identifier: OSL-3.0
 * @package Icecube_EdgeCache
 */

declare(strict_types=1);

class Icecube_EdgeCache_Model_SessionState
{
    /**
     * Named as a string and looked up through reflection, so static analysis passes on a Maho
     * with the method and on one without it
     */
    public const CORE_METHOD = 'currentHoldsState';

    /**
     * Reads the data in memory, so it works whether or not the session storage is open, and
     * before the response is sent.
     */
    public static function holdsState(): bool
    {
        if (new ReflectionClass(Mage_Core_Model_Session_Abstract::class)->hasMethod(self::CORE_METHOD)) {
            return (bool) call_user_func([Mage_Core_Model_Session_Abstract::class, self::CORE_METHOD]);
        }

        return self::stopgapHoldsState($_SESSION ?? [], self::stopgapDisposableKeys());
    }

    /*
     * Stopgap until Mage_Core_Model_Session_Abstract::currentHoldsState() is in every Maho the
     * module supports. Core keeps the disposable keys of each namespace in a private static, so
     * what follows copies Mage_Core_Model_Session_Abstract::holdsState() and the
     * getDisposableKeys() of core's session models as Maho main has them. A key that only a
     * third-party session model declares disposable counts as state here, which keeps that
     * visitor on a session render. Delete everything below, and the fallback above, once
     * core's method is there.
     */

    private const STOPGAP_BOOKKEEPING_KEYS = [
        '_sf2_attributes',
        '_symfony_flashes',
        '_sf2_meta',
        Mage_Core_Model_Session_Abstract::VALIDATOR_KEY,
        Mage_Core_Model_Session_Abstract::SECURE_COOKIE_CHECK_KEY,
    ];

    /** @var array<string, list<string>> */
    private const STOPGAP_DISPOSABLE_KEYS = [
        'core' => ['_form_key', 'last_url', 'visitor_data'],
        'catalog' => [
            'last_visited_category_id',
            'last_viewed_category_id',
            'last_viewed_product_id',
            'sort_order',
            'sort_direction',
            'display_mode',
            'limit_page',
            'catalog_compare_items_count',
        ],
        'checkout' => ['cart_was_updated'],
        'reports' => ['product_index_viewed_count', 'product_index_compared_count'],
    ];

    private const STOPGAP_CUSTOMER_DISPOSABLE_KEYS = ['wishlist_item_count'];

    /**
     * @param array<array-key, mixed> $session
     * @param array<string, list<string>> $disposableKeys
     */
    public static function stopgapHoldsState(array $session, array $disposableKeys): bool
    {
        foreach ($session as $key => $value) {
            if (in_array($key, self::STOPGAP_BOOKKEEPING_KEYS, true)) {
                continue;
            }

            if (!is_array($value)) {
                if (!self::stopgapIsEmptyValue($value)) {
                    return true;
                }
                continue;
            }

            foreach ($value as $dataKey => $data) {
                if (!self::stopgapIsEmptyValue($data) && !in_array($dataKey, $disposableKeys[$key] ?? [], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The customer session's namespace follows the account sharing scope, as its constructor sets it.
     *
     * @return array<string, list<string>>
     */
    public static function stopgapDisposableKeys(): array
    {
        $namespace = 'customer';
        if (Mage::getSingleton('customer/config_share')->isWebsiteScope()) {
            $namespace .= '_' . Mage::app()->getStore()->getWebsite()->getCode();
        }

        return [...self::STOPGAP_DISPOSABLE_KEYS, $namespace => self::STOPGAP_CUSTOMER_DISPOSABLE_KEYS];
    }

    private static function stopgapIsEmptyValue(mixed $value): bool
    {
        return $value === null
            || $value === ''
            || $value === []
            || ($value instanceof Mage_Core_Model_Message_Collection && $value->count() === 0);
    }
}
