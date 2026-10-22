<?php

declare(strict_types=1);

use Symfony\Component\HttpFoundation\Request;

/**
 * An action as core leaves it once preDispatch has decided about the session.
 */
function frontAction(string $route = 'catalog/product/view', bool $sessionless = true): Mage_Core_Controller_Front_Action
{
    [$module, $controller, $name] = explode('/', $route);
    $request = new Mage_Core_Controller_Request_Http(Request::create('http://localhost/', 'GET'));
    $request->setRouteName($module)->setControllerName($controller)->setActionName($name);
    $action = new Mage_Core_Controller_Front_Action($request, new Mage_Core_Controller_Response_Http());
    $action->setFlag('', Mage_Core_Controller_Varien_Action::FLAG_NO_START_SESSION, $sessionless);

    return $action;
}

/**
 * Dispatches nothing: hands the policy the action and response a render would have left.
 * Statuses other than 200 and 404 are hard to provoke over HTTP on an empty store, which is
 * why these live here and not in CacheableResponseTest.
 */
function sessionlessResponse(int $status, string $contentType = 'text/html; charset=UTF-8', ?string $body = null, ?Mage_Core_Controller_Front_Action $action = null): Mage_Core_Controller_Response_Http
{
    currentStore()->setConfig(Icecube_EdgeCache_Helper_Data::XML_PATH_ENABLED, '1');
    currentStore()->setConfig(Icecube_EdgeCache_Helper_Data::XML_PATH_CORE_SESSIONLESS_ENABLED, '1');

    $response = new Mage_Core_Controller_Response_Http();
    $policy = Mage::getSingleton('edgecache/policy');
    $policy->setAction($action ?? frontAction());

    $response->setHttpResponseCode($status)
        ->setHeader('Content-Type', $contentType, true)
        ->setBody($body ?? '<form><input name="form_key" value=""></form>');
    $policy->applyToResponse($response);

    return $response;
}

it('caches a page core rendered without a session', function (): void {
    $response = sessionlessResponse(200);

    expect(Mage::getSingleton('edgecache/policy')->isSessionless())->toBeTrue()
        ->and($response->getSymfonyResponse()->headers->get('Cache-Control'))->toBe('max-age=0, must-revalidate, public')
        ->and($response->getSymfonyResponse()->headers->get('X-Edge-Cache-Control'))->toBe('max-age=86400, stale-while-revalidate=86400, stale-if-error=604800');
});

it('does not cache a status other than 200 or 404', function (int $status): void {
    $response = sessionlessResponse($status);

    expect($response->getSymfonyResponse()->headers->get('Cache-Control'))->not->toContain('public')
        ->and($response->getSymfonyResponse()->headers->get('X-Edge-Cache-Control'))->toBe('no-store');
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

it('does not cache a page core rendered with a session', function (): void {
    $response = sessionlessResponse(200, action: frontAction(sessionless: false));

    expect(Mage::getSingleton('edgecache/policy')->isSessionless())->toBeFalse()
        ->and($response->getSymfonyResponse()->headers->get('X-Edge-Cache-Control'))->toBe('no-store');
});

it('caches nothing while core renders every page with a session', function (): void {
    currentStore()->setConfig(Icecube_EdgeCache_Helper_Data::XML_PATH_ENABLED, '1');
    currentStore()->setConfig(Icecube_EdgeCache_Helper_Data::XML_PATH_CORE_SESSIONLESS_ENABLED, '0');
    $policy = Mage::getSingleton('edgecache/policy');
    $policy->setAction(frontAction());
    $response = new Mage_Core_Controller_Response_Http();

    $response->setHttpResponseCode(200)->setHeader('Content-Type', 'text/html', true);
    $policy->applyToResponse($response);

    expect($policy->isSessionless())->toBeFalse()
        ->and($response->getSymfonyResponse()->headers->get('X-Edge-Cache-Control'))->toBe('no-store');
});

it('leaves an action off core list alone even when it skips the session', function (): void {
    // API endpoints and scripts skip the session for their own reasons.
    $response = sessionlessResponse(200, action: frontAction('customer/account/login'));

    expect(Mage::getSingleton('edgecache/policy')->isSessionless())->toBeFalse()
        ->and($response->getSymfonyResponse()->headers->get('X-Edge-Cache-Control'))->toBe('no-store');
});

it('reads the session flag as the response goes out, after every observer has had its say', function (): void {
    currentStore()->setConfig(Icecube_EdgeCache_Helper_Data::XML_PATH_ENABLED, '1');
    currentStore()->setConfig(Icecube_EdgeCache_Helper_Data::XML_PATH_CORE_SESSIONLESS_ENABLED, '1');
    $action = frontAction(sessionless: false);
    $policy = Mage::getSingleton('edgecache/policy');

    $policy->setAction($action);
    $action->setFlag('', Mage_Core_Controller_Varien_Action::FLAG_NO_START_SESSION, true);

    expect($policy->isSessionless())->toBeTrue();
});

it('follows a forward to an action that starts the session', function (): void {
    currentStore()->setConfig(Icecube_EdgeCache_Helper_Data::XML_PATH_ENABLED, '1');
    currentStore()->setConfig(Icecube_EdgeCache_Helper_Data::XML_PATH_CORE_SESSIONLESS_ENABLED, '1');
    $policy = Mage::getSingleton('edgecache/policy');

    $policy->setAction(frontAction());
    $policy->setAction(frontAction('customer/account/login', sessionless: false));

    expect($policy->isSessionless())->toBeFalse();
});

it('does nothing while the module is disabled', function (): void {
    currentStore()->setConfig(Icecube_EdgeCache_Helper_Data::XML_PATH_CORE_SESSIONLESS_ENABLED, '1');
    $policy = Mage::getSingleton('edgecache/policy');
    $policy->setAction(frontAction('cms/index/index'));
    $response = new Mage_Core_Controller_Response_Http();

    $response->setHttpResponseCode(200)->setHeader('Content-Type', 'text/html', true);
    $policy->applyToResponse($response);

    expect($policy->isSessionless())->toBeFalse()
        ->and($response->getSymfonyResponse()->headers->has('X-Edge-Cache-Control'))->toBeFalse();
});

it('does not cache a page that still carries a form key', function (): void {
    // Core blanks the key in postDispatch; one printed after that belongs to no session.
    $response = sessionlessResponse(200, body: '<p>' . Mage::getSingleton('core/session')->getFormKey() . '</p>');

    expect($response->getSymfonyResponse()->headers->get('X-Edge-Cache-Control'))->toBe('no-store');
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

    sessionlessResponse(200, body: '<p>' . Mage::getSingleton('core/session')->getFormKey() . '</p>');
    sessionlessResponse(200, body: '<p>' . Mage::getSingleton('core/session')->getFormKey() . '</p>');

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

it('caches nothing, and throws nothing, on a Maho without core sessionless pages', function (): void {
    currentStore()->setConfig(Icecube_EdgeCache_Helper_Data::XML_PATH_ENABLED, '1');
    // Robots, sitemaps and the image resize skip the session on any Maho
    currentStore()->setConfig(Icecube_EdgeCache_Helper_Data::XML_PATH_CORE_SESSIONLESS_ENABLED, null);
    currentStore()->setConfig(Icecube_EdgeCache_Helper_Data::XML_PATH_CORE_SESSIONLESS_ROUTES, null);
    $policy = Mage::getSingleton('edgecache/policy');
    $policy->setAction(frontAction());
    $response = new Mage_Core_Controller_Response_Http();

    $response->setHttpResponseCode(200)->setHeader('Content-Type', 'text/html', true);
    $policy->applyToResponse($response);

    expect($policy->isSessionless())->toBeFalse()
        ->and($response->getSymfonyResponse()->headers->get('X-Edge-Cache-Control'))->toBe('no-store');
});

it('reads core route list one route per line, as core does', function (string $routes, bool $listed): void {
    currentStore()->setConfig(Icecube_EdgeCache_Helper_Data::XML_PATH_CORE_SESSIONLESS_ROUTES, $routes);

    expect(Mage::helper('edgecache')->isCoreSessionlessRoute('catalog/product/view'))->toBe($listed);
})->with([
    'own line' => ["cms/index/index\n  catalog/product/view  \n", true],
    'shares a line' => ['cms/index/index, catalog/product/view', false],
]);

it('does not cache while a design exception picks the theme by User-Agent', function (string $path): void {
    // The edge keys on the URL, so one phone would hand its theme to every desktop visitor
    currentStore()->setConfig($path, serialize([['regexp' => 'iPhone', 'value' => 'mobile']]));

    $response = sessionlessResponse(200);

    expect($response->getSymfonyResponse()->headers->get('X-Edge-Cache-Control'))->toBe('no-store');
})->with(Icecube_EdgeCache_Helper_Data::XML_PATHS_DESIGN_UA_REGEXP);

it('caches a page when the design exceptions are saved empty', function (): void {
    currentStore()->setConfig('design/theme/template_ua_regexp', serialize([]));

    $response = sessionlessResponse(200);

    expect($response->getSymfonyResponse()->headers->get('Cache-Control'))->toBe('max-age=0, must-revalidate, public');
});

it('does not cache a page carrying a raw Set-Cookie header', function (): void {
    // Raw headers go out after http_response_send_before, so only the response knows of them
    currentStore()->setConfig(Icecube_EdgeCache_Helper_Data::XML_PATH_ENABLED, '1');
    currentStore()->setConfig(Icecube_EdgeCache_Helper_Data::XML_PATH_CORE_SESSIONLESS_ENABLED, '1');
    $policy = Mage::getSingleton('edgecache/policy');
    $policy->setAction(frontAction());
    $response = new Mage_Core_Controller_Response_Http();

    $response->setHttpResponseCode(200)->setHeader('Content-Type', 'text/html', true)->setRawHeader('Set-Cookie: ab_bucket=2');
    $policy->applyToResponse($response);

    expect($response->getSymfonyResponse()->headers->get('X-Edge-Cache-Control'))->toBe('no-store');
});
