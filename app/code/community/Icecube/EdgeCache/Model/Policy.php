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

    /**
     * Core's route that writes a missing resized image. Named as a string because a Maho
     * without the route has no such class.
     */
    public const IMAGE_CONTROLLER_CLASS = 'Mage_Core_ImageController';

    /** The signed resize URL that releases before the image route printed in pages and emails */
    public const IMAGE_RESIZE_ACTION = 'core_index_resize';

    /** The last front action dispatched, forwards included, so the one that rendered the response */
    private ?Mage_Core_Controller_Front_Action $action = null;

    private bool $frontendAction = false;

    /**
     * Runs for every action a request dispatches, forwards included, before the session would
     * start, and skips the session for a page that can be shared.
     */
    public function setAction(Mage_Core_Controller_Varien_Action $action): void
    {
        $this->action = $action instanceof Mage_Core_Controller_Front_Action ? $action : null;
        $this->frontendAction = $this->frontendAction || $this->action !== null;
        if ($this->action !== null && $this->canRenderWithoutSession($this->action)) {
            $this->action->setFlag('', Mage_Core_Controller_Varien_Action::FLAG_NO_START_SESSION, true);
        }
    }

    public function isSessionless(): bool
    {
        return $this->getSessionlessAction() !== null;
    }

    public function applyToResponse(Mage_Core_Controller_Response_Http $response): void
    {
        // Core's image route writes a missing resized image and sends it with a year's
        // Cache-Control. The URL changes with the image settings, and the web server serves
        // the file at it from then on, so this response gets what the file will.
        $helper = Mage::helper('edgecache');
        if (!$helper->isEnabled() || ($this->action !== null && is_a($this->action, self::IMAGE_CONTROLLER_CLASS))) {
            return;
        }

        if ($this->isNegotiatedMarkdown($response)) {
            $response->setHeader('Cache-Control', self::PRIVATE_CACHE_CONTROL, true);
            $this->sendUncacheable($response);
            return;
        }

        $sessionlessAction = $this->getSessionlessAction();
        if ($sessionlessAction === null) {
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
            // A module that always starts a session on a sessionless page would refuse every request.
            if (Mage::helper('core')->rateLimiterBy(self::REFUSAL_LOG_LIMITER, $sessionlessAction, 1, 3600)->attempt()) {
                Mage::log(sprintf('%s rendered without a session but %s, so it was not cached', $sessionlessAction, $refusal), Mage::LOG_WARNING, Icecube_EdgeCache_Model_Adapter_Noop::LOG_FILE);
            }
            $this->sendUncacheable($response);
            return;
        }

        $ttl = $response->getHttpResponseCode() === 404 ? $helper->getShortTtl() : $helper->getTtl($sessionlessAction);
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
    }

    /**
     * Statuses other than these are errors or redirects that should be fixed at the origin, not repeated.
     */
    public function isCacheableStatus(int $status): bool
    {
        return $status === 200 || $status === 404;
    }

    /**
     * Full action name of the response when it rendered without a session for sharing, null
     * otherwise. The flag is read now rather than when the action starts, so an observer that
     * sets or clears it after ours still counts. Actions off the cacheable list skip the
     * session for reasons of their own and are left alone.
     */
    private function getSessionlessAction(): ?string
    {
        $helper = Mage::helper('edgecache');
        $action = $this->action;
        if ($action === null
            || !$action->getFlag('', Mage_Core_Controller_Varien_Action::FLAG_NO_START_SESSION)
            || !$helper->isEnabled()
            || !$helper->isCacheableAction($action->getFullActionName())
        ) {
            return null;
        }

        return $action->getFullActionName();
    }

    private function canRenderWithoutSession(Mage_Core_Controller_Front_Action $action): bool
    {
        $helper = Mage::helper('edgecache');
        if (!$helper->isEnabled() || !in_array($action->getRequest()->getMethod(), ['GET', 'HEAD'], true)) {
            return false;
        }
        // It only redirects to the image file, the same for every visitor, so a first visit
        // from an old email or page shouldn't get a session cookie from an image.
        if ($action->getFullActionName() === self::IMAGE_RESIZE_ACTION) {
            return true;
        }

        return !$this->hasBypassCookie($action->getRequest())
            && $helper->isCacheableAction($action->getFullActionName());
    }

    private function hasBypassCookie(Mage_Core_Controller_Request_Http $request): bool
    {
        return array_any(Mage::helper('edgecache')->getBypassCookies(), function (string $name) use ($request): bool {
            $value = $request->getCookie($name);

            return is_string($value) && $value !== '';
        });
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
        if (Mage::helper('edgecache')->hasDesignUserAgentExceptions()) {
            return 'a design exception picks the theme by User-Agent';
        }
        // Core prints an empty key without a session. One here came from a Maho whose getFormKey()
        // makes one up, or from a module that called renewFormKey(), and belongs to no visitor's session.
        $formKey = Mage::getSingleton('core/session')->getData('_form_key');
        $body = $response->getBody();
        if (is_string($formKey) && $formKey !== '' && is_string($body) && str_contains($body, $formKey)) {
            return 'it carries a form key';
        }

        return null;
    }

    /**
     * Mage_Core_Model_Cookie calls setcookie() directly, so the Symfony header bag only sees some
     * of them, and raw headers are only sent after this runs.
     */
    private function hasCookie(Mage_Core_Controller_Response_Http $response): bool
    {
        if ($response->getSymfonyResponse()->headers->getCookies() !== []) {
            return true;
        }

        return array_any(
            [...headers_list(), ...$response->getRawHeaders()],
            fn(mixed $header): bool => is_string($header) && stripos($header, 'set-cookie:') === 0,
        );
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

    private function isHtml(Mage_Core_Controller_Response_Http $response): bool
    {
        $type = $response->getSymfonyResponse()->headers->get('Content-Type');

        return $type === null || str_starts_with($type, 'text/html');
    }

    private function isMarkdown(Mage_Core_Controller_Response_Http $response): bool
    {
        return str_starts_with((string) $response->getSymfonyResponse()->headers->get('Content-Type'), 'text/markdown');
    }
}
