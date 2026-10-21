<?php

/**
 * Decides, once per request, whether the response may be shared by the edge.
 *
 * SPDX-FileCopyrightText: 2026 Icecube
 * SPDX-License-Identifier: OSL-3.0
 * @package Icecube_EdgeCache
 */

declare(strict_types=1);

class Icecube_EdgeCache_Model_Policy
{
    public const REFUSAL_LOG_LIMITER = 'edgecache_refusal_log';

    public const BROWSER_CACHE_CONTROL = 'public, max-age=0, must-revalidate';
    public const PRIVATE_CACHE_CONTROL = 'private, no-store';

    /** Full action name of a request rendering without a session, null for every other request */
    private ?string $sessionlessAction = null;

    private bool $frontendAction = false;

    /**
     * Runs for every action a request dispatches, forwards included, so the last one decides.
     */
    public function prepareSession(Mage_Core_Controller_Varien_Action $action): void
    {
        $this->sessionlessAction = null;
        if (!$action instanceof Mage_Core_Controller_Front_Action) {
            return;
        }

        $this->frontendAction = true;
        if (!$this->canRenderWithoutSession($action)) {
            return;
        }

        $action->setFlag('', Mage_Core_Controller_Varien_Action::FLAG_NO_START_SESSION, true);
        $this->sessionlessAction = $action->getFullActionName();
    }

    public function isSessionless(): bool
    {
        return $this->sessionlessAction !== null;
    }

    /**
     * The last chance to change headers, after every other observer has had its say.
     */
    public function applyToResponse(Mage_Core_Controller_Response_Http $response): void
    {
        $helper = Mage::helper('edgecache');
        if (!$helper->isEnabled()) {
            return;
        }

        if ($this->isNegotiatedMarkdown($response)) {
            $response->setHeader('Cache-Control', self::PRIVATE_CACHE_CONTROL, true);
            $this->sendUncacheable($response);
            return;
        }

        if ($this->sessionlessAction === null) {
            if ($this->frontendAction) {
                $this->sendUncacheable($response);
            }
            return;
        }

        // Markdown at a .md URL is left to the origin: it is often served from Maho's own cache
        // before any entity loads, so it would carry no tag to purge it by.
        if (!$this->isCacheableStatus($response->getHttpResponseCode()) || $response->isRedirect() || !$this->isHtml($response)) {
            $this->sendUncacheable($response);
            return;
        }

        $refusal = $this->getRefusal($response);
        if ($refusal !== null) {
            // A module that always starts a session on an allowlisted page would refuse every request.
            if (Mage::helper('core')->rateLimiterBy(self::REFUSAL_LOG_LIMITER, $this->sessionlessAction, 1, 3600)->attempt()) {
                Mage::log(sprintf('%s rendered without a session but %s, so it was not cached', $this->sessionlessAction, $refusal), Mage::LOG_WARNING, Icecube_EdgeCache_Model_Adapter_Noop::LOG_FILE);
            }
            $this->sendUncacheable($response);
            // Its key may belong to no session, so let edgecache.js fetch a real one.
            $this->replaceFormKey($response);
            return;
        }

        $ttl = $response->getHttpResponseCode() === 404 ? $helper->getShortTtl() : $helper->getTtl($this->sessionlessAction);
        if ($ttl->maxAge <= 0) {
            $this->sendUncacheable($response);
            return;
        }

        try {
            $adapter = $helper->getAdapter();
        } catch (RuntimeException $e) {
            Mage::logException($e);
            return;
        }

        $normaliser = $helper->getTagNormaliser();
        $tags = $normaliser->cap(
            $normaliser->normalise(Mage::getSingleton('edgecache/tagCollector')->getRawTags()),
            $adapter->getLimits(),
        );

        $response->setHeader('Cache-Control', self::BROWSER_CACHE_CONTROL, true);
        foreach ($adapter->getCacheableHeaders($ttl, $tags) as $name => $value) {
            $response->setHeader($name, $value, true);
        }
        $this->removeAcceptFromVary($response);
        $this->replaceFormKey($response);
    }

    /**
     * Statuses other than these are errors or redirects that should be fixed at the origin, not repeated.
     */
    public function isCacheableStatus(int $status): bool
    {
        return $status === 200 || $status === 404;
    }

    /**
     * Why a sessionless render still cannot be shared, or null when it can.
     */
    private function getRefusal(Mage_Core_Controller_Response_Http $response): ?string
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return 'a session was started';
        }
        if ($this->hasCookie($response)) {
            return 'a cookie was set';
        }
        $body = $response->getBody();
        if (is_string($body) && str_contains($body, Icecube_EdgeCache_Helper_Data::FORM_KEY_SENTINEL)) {
            return 'it echoes the form key sentinel from the request';
        }

        return null;
    }

    /**
     * Mage_Core_Model_Cookie calls setcookie() directly, so the Symfony header bag only sees some of them.
     */
    private function hasCookie(Mage_Core_Controller_Response_Http $response): bool
    {
        if ($response->getSymfonyResponse()->headers->getCookies() !== []) {
            return true;
        }

        return array_any(headers_list(), fn(string $header): bool => stripos($header, 'set-cookie:') === 0);
    }

    /**
     * Markdown negotiated from Accept shares its URL with the HTML page, so no shared cache may keep it.
     */
    private function isNegotiatedMarkdown(Mage_Core_Controller_Response_Http $response): bool
    {
        if (!$this->isMarkdown($response) || !Mage::helper('core')->isModuleEnabled('Maho_ContentNegotiation')) {
            return false;
        }

        return !Mage::helper('contentnegotiation')->wasSuffixStripped();
    }

    private function sendUncacheable(Mage_Core_Controller_Response_Http $response): void
    {
        try {
            $headers = Mage::helper('edgecache')->getAdapter()->getUncacheableHeaders();
        } catch (RuntimeException $e) {
            Mage::logException($e);
            return;
        }

        foreach ($headers as $name => $value) {
            $response->setHeader($name, $value, true);
        }
    }

    /**
     * Core's ContentNegotiation varies HTML on Accept. Most CDNs either ignore that or split the
     * cache per browser, and markdown on these URLs is never cached (see isNegotiatedMarkdown).
     */
    private function removeAcceptFromVary(Mage_Core_Controller_Response_Http $response): void
    {
        $symfony = $response->getSymfonyResponse();
        $vary = array_values(array_filter($symfony->getVary(), fn(mixed $value): bool => is_string($value) && strcasecmp($value, 'Accept') !== 0));
        if ($vary === []) {
            $symfony->headers->remove('Vary');
            return;
        }
        $symfony->setVary($vary);
    }

    /**
     * The key in a shared page would let one visitor's key be replayed by another, and it
     * would not match any visitor's session anyway. edgecache.js puts each visitor's own key back.
     */
    private function replaceFormKey(Mage_Core_Controller_Response_Http $response): void
    {
        $formKey = Mage::getSingleton('core/session')->getFormKey();
        $body = $response->getBody();
        if (!is_string($formKey) || $formKey === '' || !is_string($body) || !str_contains($body, $formKey)) {
            return;
        }

        $response->clearBody()->setBody(str_replace($formKey, Icecube_EdgeCache_Helper_Data::FORM_KEY_SENTINEL, $body));
    }

    private function isHtml(Mage_Core_Controller_Response_Http $response): bool
    {
        $type = $response->getSymfonyResponse()->headers->get('Content-Type');

        return $type === null || str_starts_with($type, 'text/html');
    }

    private function isMarkdown(Mage_Core_Controller_Response_Http $response): bool
    {
        return str_starts_with((string) $response->getSymfonyResponse()->headers->get('Content-Type'), 'text/markdown');
    }

    private function canRenderWithoutSession(Mage_Core_Controller_Front_Action $action): bool
    {
        $helper = Mage::helper('edgecache');
        if (!$helper->isEnabled()) {
            return false;
        }

        $request = $action->getRequest();
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true) || $this->hasBypassCookie()) {
            return false;
        }

        return $helper->isCacheableAction($action->getFullActionName());
    }

    private function hasBypassCookie(): bool
    {
        $cookie = Mage::getSingleton('core/cookie');

        return array_any(Mage::helper('edgecache')->getBypassCookies(), function (string $name) use ($cookie): bool {
            $value = $cookie->get($name);

            return is_string($value) && $value !== '';
        });
    }
}
