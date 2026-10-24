<?php

declare(strict_types=1);

use Symfony\Component\HttpFoundation\Request;

/**
 * An action as the router hands it to controller_action_predispatch_session_start.
 *
 * @param array<string, string> $cookies
 */
function frontAction(string $route = 'catalog/product/view', string $method = 'GET', array $cookies = []): Mage_Core_Controller_Front_Action
{
    [$module, $controller, $name] = explode('/', $route);
    $request = new Mage_Core_Controller_Request_Http(Request::create('http://localhost/', $method, cookies: $cookies));
    $request->setRouteName($module)->setControllerName($controller)->setActionName($name);

    return new Mage_Core_Controller_Front_Action($request, new Mage_Core_Controller_Response_Http());
}

/**
 * A key a module made itself while the session never started. Core's getFormKey() gives ''
 * without a session, so it can't be what prints one.
 */
function strayFormKey(): string
{
    $session = Mage::getSingleton('core/session');
    $session->renewFormKey();

    $formKey = $session->getData('_form_key');

    return is_string($formKey) ? $formKey : '';
}

/**
 * Stands in for a Maho that has no Mage_Core_Model_Session::FORM_KEY_ROUTE.
 */
function withoutCoreFormKeyFallback(): void
{
    Mage::register('_helper/edgecache', new class extends Icecube_EdgeCache_Helper_Data {
        #[\Override]
        public function hasCoreFormKeyFallback(): bool
        {
            return false;
        }
    });
}

function skipsSession(Mage_Core_Controller_Front_Action $action): bool
{
    return (bool) $action->getFlag('', Mage_Core_Controller_Varien_Action::FLAG_NO_START_SESSION);
}

/**
 * Dispatches nothing: hands the policy the action and the response a render would have left.
 * Statuses other than 200 and 404 are hard to provoke over HTTP on an empty store, which is
 * why these live here and not in CacheableResponseTest.
 */
function sessionlessResponse(int $status, string $contentType = 'text/html; charset=UTF-8', ?string $body = null, ?Mage_Core_Controller_Front_Action $action = null): Mage_Core_Controller_Response_Http
{
    currentStore()->setConfig(Icecube_EdgeCache_Helper_Data::XML_PATH_ENABLED, '1');

    $response = new Mage_Core_Controller_Response_Http();
    $policy = Mage::getSingleton('edgecache/policy');
    $policy->setAction($action ?? frontAction());

    $response->setHttpResponseCode($status)
        ->setHeader('Content-Type', $contentType, true)
        ->setBody($body ?? '<form><input name="form_key" value=""></form>');
    $policy->applyToResponse($response);

    return $response;
}

it('skips the session for a listed GET and caches the page', function (): void {
    $action = frontAction();

    $response = sessionlessResponse(200, action: $action);

    expect(skipsSession($action))->toBeTrue()
        ->and(Mage::getSingleton('edgecache/policy')->isSessionless())->toBeTrue()
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

it('keeps a session for a request that is not GET or HEAD', function (): void {
    $action = frontAction(method: 'POST');

    $response = sessionlessResponse(200, action: $action);

    expect(skipsSession($action))->toBeFalse()
        ->and($response->getSymfonyResponse()->headers->get('X-Edge-Cache-Control'))->toBe('no-store');
});

it('keeps a session for a request carrying a bypass cookie', function (string $cookie): void {
    $action = frontAction(cookies: [$cookie => 'x']);

    $response = sessionlessResponse(200, action: $action);

    expect(skipsSession($action))->toBeFalse()
        ->and($response->getSymfonyResponse()->headers->get('X-Edge-Cache-Control'))->toBe('no-store');
})->with(['maho_session', 'frontend', 'om_frontend', 'store', 'user_allowed_save_cookie', 'maho_a11y_scan']);

it('keeps a session for a session cookie even with the bypass list cleared', function (): void {
    currentStore()->setConfig(Icecube_EdgeCache_Helper_Data::XML_PATH_BYPASS_COOKIES, '');
    $action = frontAction(cookies: ['maho_session' => 'x']);

    sessionlessResponse(200, action: $action);

    expect(skipsSession($action))->toBeFalse();
});

it('ignores a bypass cookie sent empty', function (): void {
    $action = frontAction(cookies: ['maho_session' => '', 'store' => '']);

    sessionlessResponse(200, action: $action);

    expect(skipsSession($action))->toBeTrue();
});

it('reads the cacheable list one name per line, splitting on spaces and commas too', function (string $list, bool $listed): void {
    currentStore()->setConfig(Icecube_EdgeCache_Helper_Data::XML_PATH_CACHEABLE_ACTIONS, $list);

    expect(Mage::helper('edgecache')->isCacheableAction('catalog_product_view'))->toBe($listed);
})->with([
    'own line, padded' => ["cms_index_index\n  catalog_product_view  \n", true],
    'Windows line ends' => ["cms_index_index\r\ncatalog_product_view\r\n", true],
    'comma separated' => ['cms_index_index, catalog_product_view', true],
    'not listed' => ["cms_index_index\ncatalog_product_viewer", false],
    'empty' => ['', false],
]);

it('skips the session on the legacy resize URL whatever cookies the request carries', function (bool $fallback): void {
    // Core without resize-without-session would start one, and the image would hand out the cookie
    if (!$fallback) {
        withoutCoreFormKeyFallback();
    }
    currentStore()->setConfig(Icecube_EdgeCache_Helper_Data::XML_PATH_ENABLED, '1');
    $action = frontAction('core/index/resize', cookies: ['maho_session' => 'x', 'store' => 'default']);

    Mage::getSingleton('edgecache/policy')->setAction($action);

    expect(skipsSession($action))->toBeTrue();
})->with(['with core form key fallback' => true, 'without it' => false]);

it('leaves an action off the list alone', function (): void {
    $action = frontAction('customer/account/login');

    $response = sessionlessResponse(200, action: $action);

    expect(skipsSession($action))->toBeFalse()
        ->and($response->getSymfonyResponse()->headers->get('X-Edge-Cache-Control'))->toBe('no-store');
});

it('never skips the session on core form key route, even when it is listed', function (): void {
    currentStore()->setConfig(Icecube_EdgeCache_Helper_Data::XML_PATH_CACHEABLE_ACTIONS, "catalog_product_view\ncore_index_formKey");
    $action = frontAction('core/index/formKey');

    $response = sessionlessResponse(200, action: $action);

    expect(skipsSession($action))->toBeFalse()
        ->and($response->getSymfonyResponse()->headers->get('X-Edge-Cache-Control'))->toBe('no-store');
});

it('sets the flag from an observer outside the frontend area, which loads after the event', function (): void {
    // The test boots no area, as core hasn't loaded the frontend one when it fires the event
    currentStore()->setConfig(Icecube_EdgeCache_Helper_Data::XML_PATH_ENABLED, '1');
    $action = frontAction();

    Mage::dispatchEvent('controller_action_predispatch_session_start', ['controller_action' => $action]);

    expect(skipsSession($action))->toBeTrue();
});

it('leaves alone an action off the list that skips the session itself', function (): void {
    // Robots, sitemaps and API endpoints set the flag for reasons of their own
    $action = frontAction('customer/account/login');
    $action->setFlag('', Mage_Core_Controller_Varien_Action::FLAG_NO_START_SESSION, true);

    $response = sessionlessResponse(200, action: $action);

    expect(Mage::getSingleton('edgecache/policy')->isSessionless())->toBeFalse()
        ->and($response->getSymfonyResponse()->headers->get('X-Edge-Cache-Control'))->toBe('no-store');
});

it('reads the session flag as the response goes out, after every observer has had its say', function (): void {
    currentStore()->setConfig(Icecube_EdgeCache_Helper_Data::XML_PATH_ENABLED, '1');
    $action = frontAction();
    $policy = Mage::getSingleton('edgecache/policy');

    $policy->setAction($action);
    $action->setFlag('', Mage_Core_Controller_Varien_Action::FLAG_NO_START_SESSION, false);

    expect($policy->isSessionless())->toBeFalse();
});

it('lets a forward to an action off the list start the session', function (): void {
    currentStore()->setConfig(Icecube_EdgeCache_Helper_Data::XML_PATH_ENABLED, '1');
    $policy = Mage::getSingleton('edgecache/policy');
    $forwarded = frontAction('customer/account/login');

    $policy->setAction(frontAction());
    $policy->setAction($forwarded);

    expect($policy->isSessionless())->toBeFalse()
        ->and(skipsSession($forwarded))->toBeFalse();
});

it('does nothing while the module is disabled', function (): void {
    $action = frontAction('cms/index/index');
    $policy = Mage::getSingleton('edgecache/policy');
    $policy->setAction($action);
    $response = new Mage_Core_Controller_Response_Http();

    $response->setHttpResponseCode(200)->setHeader('Content-Type', 'text/html', true);
    $policy->applyToResponse($response);

    expect(skipsSession($action))->toBeFalse()
        ->and($response->getSymfonyResponse()->headers->has('X-Edge-Cache-Control'))->toBeFalse();
});

it('keeps the session and caches nothing on a Maho without the core form key fallback', function (): void {
    // Without it the page would carry a key no session knows, and every form on it would fail
    withoutCoreFormKeyFallback();
    $action = frontAction();

    $response = sessionlessResponse(200, action: $action);

    expect(skipsSession($action))->toBeFalse()
        ->and(Mage::getSingleton('edgecache/policy')->isSessionless())->toBeFalse()
        ->and($response->getSymfonyResponse()->headers->get('X-Edge-Cache-Control'))->toBe('no-store');
});

it('does not cache a page that still carries a form key', function (): void {
    $response = sessionlessResponse(200, body: '<p>' . strayFormKey() . '</p>');

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

    sessionlessResponse(200, body: '<p>' . strayFormKey() . '</p>');
    sessionlessResponse(200, body: '<p>' . strayFormKey() . '</p>');

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
    $policy = Mage::getSingleton('edgecache/policy');
    $policy->setAction(frontAction());
    $response = new Mage_Core_Controller_Response_Http();

    $response->setHttpResponseCode(200)->setHeader('Content-Type', 'text/html', true)->setRawHeader('Set-Cookie: ab_bucket=2');
    $policy->applyToResponse($response);

    expect($response->getSymfonyResponse()->headers->get('X-Edge-Cache-Control'))->toBe('no-store');
});
