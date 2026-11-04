<?php

/**
 * Sends edgecache_state next to the session cookie: 0 while the session holds no state, 1 once
 * it does. A listed page renders without a session for a session cookie only next to a 0, and
 * the CDN serves its cached copy to the same requests.
 *
 * SPDX-FileCopyrightText: 2026 Icecube
 * SPDX-License-Identifier: OSL-3.0
 * @package Icecube_EdgeCache
 */

declare(strict_types=1);

class Icecube_EdgeCache_Model_StateCookie
{
    public const NAME = 'edgecache_state';
    public const NO_STATE = '0';
    public const HOLDS_STATE = '1';

    private bool $sessionStarted = false;
    private bool $sent = false;

    /**
     * Called as the storefront session starts and sends its cookie. The cookie goes out from
     * http_response_send_before, where the response guard sees it, and the header callback
     * covers a request that never gets there: an uncaught exception, or an action that exits.
     */
    public function markSessionStarted(): void
    {
        if ($this->sessionStarted) {
            return;
        }
        $this->sessionStarted = true;
        // PHP keeps one callback. Neither Maho nor its dependencies register one.
        header_register_callback($this->sendFromHeaderCallback(...));
    }

    public function wasSessionStarted(): bool
    {
        return $this->sessionStarted;
    }

    /**
     * Sends the cookie once, after the request has done everything that can store state.
     */
    public function send(): void
    {
        if (!$this->sessionStarted || $this->sent) {
            return;
        }
        $this->sent = true;

        $cookie = Mage::getSingleton('core/cookie');
        if (!Mage::helper('edgecache')->isEnabled() || $this->endsSession()) {
            // A 0 left behind would let the CDN serve its cached pages to a session this
            // module no longer watches
            if ($cookie->get(self::NAME) !== false) {
                $cookie->delete(self::NAME);
            }
            return;
        }

        $cookie->set(self::NAME, Icecube_EdgeCache_Model_SessionState::holdsState() ? self::HOLDS_STATE : self::NO_STATE);
    }

    private function sendFromHeaderCallback(): void
    {
        try {
            $this->send();
        } catch (Throwable $e) {
            Mage::logException($e);
        }
    }

    /**
     * True when the response deletes the session cookie and sends no new one, as a failed
     * session validation does. Core deletes the cookie before it sends the new id on logout,
     * and deletes it for parent domains after.
     */
    private function endsSession(): bool
    {
        $prefix = 'set-cookie: ' . strtolower(Mage_Core_Controller_Front_Action::SESSION_NAMESPACE) . '=';
        $deleted = false;
        foreach (headers_list() as $header) {
            if (!str_starts_with(strtolower($header), $prefix)) {
                continue;
            }
            $value = explode(';', substr($header, strlen($prefix)), 2)[0];
            if ($value !== '' && $value !== 'deleted') {
                return false;
            }
            $deleted = true;
        }

        return $deleted;
    }
}
