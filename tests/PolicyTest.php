<?php

declare(strict_types=1);

use Symfony\Component\HttpFoundation\Request;

/**
 * Dispatches nothing: builds the request and action a sessionless render would have and asks
 * the policy about the response. Statuses other than 200 and 404 are hard to provoke over
 * HTTP on an empty store, which is why these live here and not in CacheableResponseTest.
 */
function sessionlessResponse(int $status, string $contentType = 'text/html; charset=UTF-8', ?string $body = null): Mage_Core_Controller_Response_Http
{
    currentStore()->setConfig(Icecube_EdgeCache_Helper_Data::XML_PATH_ENABLED, '1');

    $request = new Mage_Core_Controller_Request_Http(Request::create('http://localhost/', 'GET'));
    $request->setRouteName('catalog')->setControllerName('product')->setActionName('view');
    $response = new Mage_Core_Controller_Response_Http();
    $action = new Mage_Core_Controller_Front_Action($request, $response);

    $policy = Mage::getSingleton('edgecache/policy');
    $policy->prepareSession($action);

    $response->setHttpResponseCode($status)
        ->setHeader('Content-Type', $contentType, true)
        ->setBody($body ?? '<form><input name="form_key" value="' . Mage::getSingleton('core/session')->getFormKey() . '"></form>');
    $policy->applyToResponse($response);

    return $response;
}

it('skips the session for an allowlisted GET', function (): void {
    $response = sessionlessResponse(200);

    expect(Mage::getSingleton('edgecache/policy')->isSessionless())->toBeTrue()
        ->and($response->getSymfonyResponse()->headers->get('Cache-Control'))->toBe('max-age=0, must-revalidate, public')
        ->and($response->getBody())->toContain('value="__EDGEFK__"');
});

it('does not cache a status other than 200 or 404', function (int $status): void {
    $response = sessionlessResponse($status);

    expect($response->getSymfonyResponse()->headers->get('Cache-Control'))->not->toContain('public')
        ->and($response->getSymfonyResponse()->headers->get('X-Edge-Cache-Control'))->toBe('no-store')
        ->and($response->getBody())->not->toContain('__EDGEFK__');
})->with([301, 302, 403, 500, 503]);

it('does not cache a 404 when the short TTL is off', function (): void {
    currentStore()->setConfig(Icecube_EdgeCache_Helper_Data::XML_PATH_SHORT_TTL, '0');

    $response = sessionlessResponse(404);

    expect($response->getSymfonyResponse()->headers->get('X-Edge-Cache-Control'))->toBe('no-store');
});

it('does not cache a response that is not HTML', function (): void {
    $response = sessionlessResponse(200, 'application/json');

    expect($response->getSymfonyResponse()->headers->get('Cache-Control'))->not->toContain('public');
});

it('makes negotiated markdown private', function (): void {
    $response = sessionlessResponse(200, 'text/markdown; charset=UTF-8');

    expect($response->getSymfonyResponse()->headers->get('Cache-Control'))->toBe('no-store, private');
});

it('keeps a session for a request that is not GET or HEAD', function (): void {
    currentStore()->setConfig(Icecube_EdgeCache_Helper_Data::XML_PATH_ENABLED, '1');
    $request = new Mage_Core_Controller_Request_Http(Request::create('http://localhost/', 'POST'));
    $request->setRouteName('catalog')->setControllerName('product')->setActionName('view');
    $action = new Mage_Core_Controller_Front_Action($request, new Mage_Core_Controller_Response_Http());

    Mage::getSingleton('edgecache/policy')->prepareSession($action);

    expect($action->getFlag('', Mage_Core_Controller_Varien_Action::FLAG_NO_START_SESSION))->toBeFalsy();
});

it('lets a forward to an action that is not allowlisted start the session', function (): void {
    currentStore()->setConfig(Icecube_EdgeCache_Helper_Data::XML_PATH_ENABLED, '1');
    $policy = Mage::getSingleton('edgecache/policy');
    $request = new Mage_Core_Controller_Request_Http(Request::create('http://localhost/', 'GET'));
    $request->setRouteName('catalog')->setControllerName('product')->setActionName('view');
    $policy->prepareSession(new Mage_Core_Controller_Front_Action($request, new Mage_Core_Controller_Response_Http()));

    $request->setRouteName('customer')->setControllerName('account')->setActionName('login');
    $forwarded = new Mage_Core_Controller_Front_Action($request, new Mage_Core_Controller_Response_Http());
    $policy->prepareSession($forwarded);

    expect($policy->isSessionless())->toBeFalse()
        ->and($forwarded->getFlag('', Mage_Core_Controller_Varien_Action::FLAG_NO_START_SESSION))->toBeFalsy();
});

it('does nothing while the module is disabled', function (): void {
    $request = new Mage_Core_Controller_Request_Http(Request::create('http://localhost/', 'GET'));
    $request->setRouteName('cms')->setControllerName('index')->setActionName('index');
    $action = new Mage_Core_Controller_Front_Action($request, new Mage_Core_Controller_Response_Http());

    Mage::getSingleton('edgecache/policy')->prepareSession($action);

    expect(Mage::getSingleton('edgecache/policy')->isSessionless())->toBeFalse();
});

it('logs a refused render once an hour per action', function (): void {
    // The logger rotates daily, so edgecache.log is written as edgecache-<date>.log.
    $countLines = fn(): int => array_sum(array_map(
        fn(string $file): int => substr_count((string) file_get_contents($file), 'catalog_product_view rendered without a session'),
        glob(Mage::getBaseDir('var') . '/log/edgecache*.log') ?: [],
    ));
    currentStore()->setConfig('dev/log/active', '1');
    Mage::helper('core')->rateLimiterBy(Icecube_EdgeCache_Model_Policy::REFUSAL_LOG_LIMITER, 'catalog_product_view', 1, 3600)->clear();
    $before = $countLines();

    sessionlessResponse(200, body: '<p>' . Icecube_EdgeCache_Helper_Data::FORM_KEY_SENTINEL . '</p>');
    sessionlessResponse(200, body: '<p>' . Icecube_EdgeCache_Helper_Data::FORM_KEY_SENTINEL . '</p>');

    expect($countLines() - $before)->toBe(1);
});

it('does not cache a sessionless render that started a session after all', function (): void {
    // A module calling session_start() itself, which no cookie check would catch in the CLI.
    $useCookies = ini_set('session.use_cookies', '0');
    $cacheLimiter = ini_set('session.cache_limiter', '');
    session_start();

    try {
        $response = sessionlessResponse(200);
    } finally {
        session_abort();
        ini_set('session.use_cookies', (string) $useCookies);
        ini_set('session.cache_limiter', (string) $cacheLimiter);
    }

    expect($response->getSymfonyResponse()->headers->get('X-Edge-Cache-Control'))->toBe('no-store');
});
