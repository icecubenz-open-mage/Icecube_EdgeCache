<?php

declare(strict_types=1);

use Icecube\EdgeCache\Tests\Support\Catalogue;
use Icecube\EdgeCache\Tests\Support\HttpServer;
use Symfony\Contracts\HttpClient\ResponseInterface;

const STATE_SERVER_CONFIG = ['edgecache/general/enabled' => '1'];
const STATE_CUSTOMER_EMAIL = 'edgecache-state@example.com';
const STATE_CUSTOMER_PASSWORD = 'Edge-cache-state-2026!';

beforeEach(function (): void {
    Catalogue::ensure();
    HttpServer::start(STATE_SERVER_CONFIG);
});

/**
 * Keeps the cookies a browser would, from one response to the next.
 */
final class StateCookieJar
{
    /** @var array<string, string> */
    public array $cookies = [];

    public function request(string $method, string $path, array $body = [], array $headers = []): ResponseInterface
    {
        if ($this->cookies !== []) {
            $headers['Cookie'] = implode('; ', array_map(fn(string $name, string $value): string => $name . '=' . $value, array_keys($this->cookies), $this->cookies));
        }
        $response = HttpServer::request($method, $path, $headers, $body);
        foreach (stateSetCookies($response) as $name => $cookie) {
            if ($cookie['value'] === '' || $cookie['value'] === 'deleted') {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = $cookie['value'];
            }
        }

        return $response;
    }

    public function formKey(): string
    {
        $formKey = json_decode($this->request('GET', '/edgecache/formkey/')->getContent(), true)['form_key'] ?? null;

        return is_string($formKey) ? $formKey : '';
    }
}

/**
 * The last Set-Cookie of each name, with its attributes in lower case.
 *
 * @return array<string, array{value: string, attributes: array<string, string>}>
 */
function stateSetCookies(ResponseInterface $response): array
{
    $cookies = [];
    foreach ($response->getHeaders(false)['set-cookie'] ?? [] as $header) {
        $parts = array_map(trim(...), explode(';', $header));
        [$name, $value] = explode('=', (string) array_shift($parts), 2) + [1 => ''];
        $attributes = [];
        foreach ($parts as $part) {
            [$key, $attribute] = explode('=', $part, 2) + [1 => ''];
            $attributes[strtolower($key)] = $attribute;
        }
        $cookies[$name] = ['value' => $value, 'attributes' => $attributes];
    }

    return $cookies;
}

function stateCookieValue(ResponseInterface $response): ?string
{
    return stateSetCookies($response)[Icecube_EdgeCache_Model_StateCookie::NAME]['value'] ?? null;
}

function isEdgeCacheable(ResponseInterface $response): bool
{
    $ttl = $response->getHeaders(false)['x-edge-cache-control'] ?? [];

    return ($response->getHeaders(false)['set-cookie'] ?? []) === []
        && str_contains($ttl[0] ?? '', 'max-age=86400');
}

function minicartCount(ResponseInterface $response): ?int
{
    return preg_match('#<span class="count count-(\d+)">#', $response->getContent(false), $match) === 1 ? (int) $match[1] : null;
}

function stateCustomer(): void
{
    $customer = Mage::getModel('customer/customer')->setWebsiteId(1)->loadByEmail(STATE_CUSTOMER_EMAIL);
    if (!$customer->getId()) {
        $customer->setWebsiteId(1)->setStoreId(1)->setFirstname('Edge')->setLastname('Cache')
            ->setEmail(STATE_CUSTOMER_EMAIL)->setPassword(STATE_CUSTOMER_PASSWORD)->save();
    }
    // Stores can ask new accounts to confirm their email before they log in
    $customer->setConfirmation(null)->save();
}

function addToCart(StateCookieJar $jar): ResponseInterface
{
    return $jar->request('POST', '/checkout/cart/add/', [
        'form_key' => $jar->formKey(),
        'product' => (string) Catalogue::ensure()['product'],
        'qty' => '1',
    ]);
}

it('sends no cookie to an anonymous first visit, and lets the edge keep the page', function (): void {
    $response = HttpServer::request('GET', '/edgecache-category/');

    expect(isEdgeCacheable($response))->toBeTrue();
});

it('sends 0 next to the session cookie of a session that holds nothing, with the same attributes', function (): void {
    $response = new StateCookieJar()->request('GET', '/edgecache/formkey/');
    $cookies = stateSetCookies($response);

    expect($cookies[Icecube_EdgeCache_Model_StateCookie::NAME]['value'] ?? null)->toBe('0')
        ->and($cookies[Icecube_EdgeCache_Model_StateCookie::NAME]['attributes'])->toBe($cookies['maho_session']['attributes']);
});

it('renders a listed page without a session for a session cookie next to 0', function (): void {
    $jar = new StateCookieJar();
    $jar->request('GET', '/edgecache/formkey/');

    $response = $jar->request('GET', '/edgecache-category/');

    expect($jar->cookies)->toHaveKey('maho_session')
        ->and(isEdgeCacheable($response))->toBeTrue()
        ->and($response->getContent())->toContain("FORM_KEY = ''");
});

it('starts the session for a session cookie without 0 next to it, and sends the state', function (?string $state): void {
    $cookie = 'maho_session=' . str_repeat('b', 32) . ($state === null ? '' : '; edgecache_state=' . $state);

    $response = HttpServer::request('GET', '/edgecache-category/', ['Cookie' => $cookie]);

    expect(isEdgeCacheable($response))->toBeFalse()
        ->and($response->getHeaders(false)['x-edge-cache-control'] ?? [])->toBe(['no-store'])
        ->and(stateCookieValue($response))->toBe('0');
})->with(['missing' => null, 'holds state' => '1', 'empty' => '', 'anything else' => 'x']);

it('keeps the bypass cookies bypassing next to 0', function (string $cookie): void {
    $response = HttpServer::request('GET', '/edgecache-category/', ['Cookie' => 'maho_session=' . str_repeat('b', 32) . '; edgecache_state=0; ' . $cookie]);

    expect($response->getHeaders(false)['x-edge-cache-control'] ?? [])->toBe(['no-store']);
})->with(['frontend=' . str_repeat('a', 32), 'om_frontend=' . str_repeat('a', 32), 'store=default', 'user_allowed_save_cookie=' . rawurlencode('{"1":1}'), 'maho_a11y_scan=x']);

it('flips to 1 on add to cart, and the next listed page shows the cart from a session render', function (): void {
    $jar = new StateCookieJar();
    $jar->request('GET', '/edgecache/formkey/');

    $add = addToCart($jar);
    $page = $jar->request('GET', '/edgecache-category/');

    expect($add->getStatusCode())->toBe(302)
        ->and(stateCookieValue($add))->toBe('1')
        ->and(isEdgeCacheable($page))->toBeFalse()
        ->and(stateCookieValue($page))->toBe('1')
        ->and(minicartCount($page))->toBe(1);
});

it('gives a session with a cart but no state cookie, from before the module sent one, a session render', function (): void {
    $jar = new StateCookieJar();
    $jar->request('GET', '/edgecache/formkey/');
    addToCart($jar);
    unset($jar->cookies[Icecube_EdgeCache_Model_StateCookie::NAME]);

    $page = $jar->request('GET', '/edgecache-category/');

    expect(isEdgeCacheable($page))->toBeFalse()
        ->and(minicartCount($page))->toBe(1)
        ->and(stateCookieValue($page))->toBe('1');
});

it('gives a session with a cart sent with a forged 0 the shared page, which shows nothing of the cart', function (): void {
    $jar = new StateCookieJar();
    $jar->request('GET', '/edgecache/formkey/');
    addToCart($jar);
    $jar->cookies[Icecube_EdgeCache_Model_StateCookie::NAME] = Icecube_EdgeCache_Model_StateCookie::NO_STATE;

    $page = $jar->request('GET', '/edgecache-category/');
    $anonymous = HttpServer::request('GET', '/edgecache-category/');

    expect(isEdgeCacheable($page))->toBeTrue()
        ->and(minicartCount($page))->toBe(minicartCount($anonymous))
        ->and(minicartCount($page))->not->toBe(1);
});

it('keeps 1 for a cart emptied again, whose quote the session still holds', function (): void {
    $jar = new StateCookieJar();
    $jar->request('GET', '/edgecache/formkey/');
    addToCart($jar);
    $resource = Mage::getSingleton('core/resource');
    $itemId = (int) $resource->getConnection('core_read')->fetchOne('SELECT MAX(item_id) FROM ' . $resource->getTableName('sales/quote_item'));

    $jar->request('POST', '/checkout/cart/delete/', ['id' => (string) $itemId, 'form_key' => $jar->formKey()]);
    $page = $jar->request('GET', '/edgecache-category/');

    expect(minicartCount($page))->toBe(0)
        ->and(stateCookieValue($page))->toBe('1');
});

it('flips to 1 on login and stays there after logout, which leaves the url to return to', function (): void {
    stateCustomer();
    $jar = new StateCookieJar();
    $jar->request('GET', '/edgecache/formkey/');

    $login = $jar->request('POST', '/customer/account/loginPost/', [
        'form_key' => $jar->formKey(),
        'login[username]' => STATE_CUSTOMER_EMAIL,
        'login[password]' => STATE_CUSTOMER_PASSWORD,
    ]);
    $loggedIn = $jar->request('GET', '/edgecache-category/');
    $logout = $jar->request('GET', '/customer/account/logout/');

    expect($login->getStatusCode())->toBe(302)
        ->and(stateCookieValue($login))->toBe('1')
        ->and(isEdgeCacheable($loggedIn))->toBeFalse()
        // The guest order lookup is in the footer for guests only
        ->and($loggedIn->getContent())->not->toContain('sales/guest/form')
        ->and(stateCookieValue($logout))->toBe('1')
        ->and($jar->cookies)->toHaveKey('maho_session');
});

it('flips to 1 for a message, and back to 0 once a page has shown it', function (): void {
    $jar = new StateCookieJar();
    $jar->request('GET', '/edgecache/formkey/');

    // A wrong form key leaves an error for the next page
    $refused = $jar->request('POST', '/checkout/cart/add/', ['form_key' => 'wrong', 'product' => (string) Catalogue::ensure()['product']]);
    $page = $jar->request('GET', '/edgecache-category/');
    $next = $jar->request('GET', '/edgecache-category/');

    expect($refused->getStatusCode())->toBe(302)
        ->and(stateCookieValue($refused))->toBe('1')
        ->and(isEdgeCacheable($page))->toBeFalse()
        ->and($page->getContent())->toContain('Invalid form key')
        ->and(stateCookieValue($page))->toBe('0')
        ->and(isEdgeCacheable($next))->toBeTrue();
});

it('deletes the state cookie of a session that starts while the module is off', function (): void {
    HttpServer::start(['edgecache/general/enabled' => '0']);

    $response = HttpServer::request('GET', '/edgecache-category/', ['Cookie' => 'maho_session=' . str_repeat('b', 32) . '; edgecache_state=0']);

    expect(stateSetCookies($response)[Icecube_EdgeCache_Model_StateCookie::NAME]['value'] ?? null)->toBe('deleted');
});

it('sends no state cookie to a response that started no session', function (): void {
    $response = HttpServer::request('GET', '/edgecache-category/', ['Cookie' => 'edgecache_state=1']);

    expect(isEdgeCacheable($response))->toBeTrue();
});
