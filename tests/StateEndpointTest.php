<?php

declare(strict_types=1);

use Icecube\EdgeCache\Tests\Support\HttpServer;
use Icecube\EdgeCache\Tests\Support\Catalogue;

beforeEach(function (): void {
    Catalogue::ensure();
    HttpServer::start(['edgecache/general/enabled' => '1']);
});

/**
 * @return array{cookie: string, state: array<mixed>}
 */
function fetchState(): array
{
    $response = HttpServer::request('GET', '/edgecache/state/');
    $setCookies = implode("\n", $response->getHeaders(false)['set-cookie'] ?? []);
    preg_match('/maho_session=([^;]+)/', $setCookies, $match);
    $state = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);

    return ['cookie' => 'maho_session=' . ($match[1] ?? ''), 'state' => is_array($state) ? $state : []];
}

it('returns the visitor state as private JSON with a real form key', function (): void {
    $response = HttpServer::request('GET', '/edgecache/state/');
    $state = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getHeaders()['cache-control'] ?? [])->toBe(['no-store, private'])
        ->and($response->getHeaders()['content-type'][0] ?? '')->toStartWith('application/json')
        ->and($state)->toMatchArray([
            'v' => 1,
            'customer' => ['loggedIn' => false, 'group' => 0],
            'cart' => ['qty' => 0],
        ])
        ->and(is_array($state) ? $state['formKey'] : null)->toMatch('/^[A-Za-z0-9]{16}$/');
});

it('tells the edge not to store the state and starts the session it reports on', function (): void {
    $response = HttpServer::request('GET', '/edgecache/state/');
    $cookies = implode("\n", $response->getHeaders(false)['set-cookie'] ?? []);

    expect($response->getHeaders(false)['x-edge-cache-control'] ?? [])->toBe(['no-store'])
        ->and($response->getHeaders(false)['x-edge-tags'] ?? [])->toBe([])
        ->and($cookies)->toContain('maho_session=')
        ->and($cookies)->toContain('edgecache_s=1');
});

it('hands out a form key that passes the CSRF check on a POST', function (): void {
    ['cookie' => $cookie, 'state' => $state] = fetchState();
    $ids = Catalogue::ensure();

    $response = HttpServer::request('POST', '/checkout/cart/add/?isAjax=1', ['Cookie' => $cookie], [
        'form_key' => is_string($state['formKey'] ?? null) ? $state['formKey'] : '',
        'product' => (string) $ids['product'],
        'qty' => '1',
    ]);

    expect($response->getContent(false))->not->toContain('Invalid form key')
        ->and(json_decode($response->getContent(false), true))->toMatchArray(['success' => true]);

    $after = json_decode(HttpServer::request('GET', '/edgecache/state/', ['Cookie' => $cookie])->getContent(), true);
    expect(is_array($after) ? $after['cart'] : null)->toBe(['qty' => 1]);
});

it('rejects a POST carrying the sentinel instead of a key', function (): void {
    ['cookie' => $cookie] = fetchState();
    $ids = Catalogue::ensure();

    $response = HttpServer::request('POST', '/checkout/cart/add/?isAjax=1', ['Cookie' => $cookie], [
        'form_key' => Icecube_EdgeCache_Helper_Data::FORM_KEY_SENTINEL,
        'product' => (string) $ids['product'],
    ]);

    expect($response->getContent(false))->toContain('Invalid form key');
});
