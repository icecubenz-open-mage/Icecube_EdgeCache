<?php

declare(strict_types=1);

use Icecube_EdgeCache_Model_Adapter_Cloudflare as Cloudflare;
use Icecube_EdgeCache_Model_Adapter_Varnish as Varnish;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Collects each request the adapter makes and answers it with the given body and status.
 *
 * @param list<array{method: string, url: string, headers: list<string>, body: string}> $requests
 * @param list<string> $responseHeaders
 */
function recordingClient(array &$requests, string $body = '{"success":true,"errors":[],"messages":[],"result":{"id":"x"}}', int $status = 200, array $responseHeaders = []): MockHttpClient
{
    return new MockHttpClient(function (string $method, string $url, array $options) use (&$requests, $body, $status, $responseHeaders): MockResponse {
        $headers = $options['headers'] ?? [];
        $requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => is_array($headers) ? array_values(array_filter($headers, is_string(...))) : [],
            'body' => is_string($options['body'] ?? null) ? $options['body'] : '',
        ];

        return new MockResponse($body, ['http_code' => $status, 'response_headers' => $responseHeaders]);
    });
}

function configureCloudflare(): void
{
    currentStore()->setConfig(Cloudflare::XML_PATH_ZONE_ID, 'example-zone-id');
    currentStore()->setConfig(Cloudflare::XML_PATH_API_TOKEN, Mage::helper('core')->encrypt('secret-token'));
}

it('purges Cloudflare by tag the way the zone purge API documents', function (): void {
    configureCloudflare();
    $requests = [];

    Mage::getModel('edgecache/adapter_cloudflare')
        ->setHttpClient(recordingClient($requests))
        ->purgeTags(['p12', 'c3']);

    expect($requests)->toHaveCount(1)
        ->and($requests[0]['method'])->toBe('POST')
        ->and($requests[0]['url'])->toBe('https://api.cloudflare.com/client/v4/zones/example-zone-id/purge_cache')
        ->and($requests[0]['headers'])->toContain('Authorization: Bearer secret-token')
        ->and($requests[0]['headers'])->toContain('Content-Type: application/json')
        ->and(json_decode($requests[0]['body'], true))->toBe(['tags' => ['p12', 'c3']]);
});

it('purges Cloudflare by URL and purges everything on its own', function (): void {
    configureCloudflare();
    $requests = [];
    $adapter = Mage::getModel('edgecache/adapter_cloudflare')->setHttpClient(recordingClient($requests));

    $adapter->purgeUrls(['https://shop.example/a/']);
    $adapter->purgeAll();

    expect(json_decode($requests[0]['body'], true))->toBe(['files' => ['https://shop.example/a/']])
        ->and(json_decode($requests[1]['body'], true))->toBe(['purge_everything' => true]);
});

it('invalidates everything through the zone invalidate API', function (): void {
    configureCloudflare();
    $requests = [];

    Mage::getModel('edgecache/adapter_cloudflare')
        ->setHttpClient(recordingClient($requests))
        ->invalidateAll();

    expect($requests)->toHaveCount(1)
        ->and($requests[0]['url'])->toBe('https://api.cloudflare.com/client/v4/zones/example-zone-id/invalidate_cache')
        ->and($requests[0]['headers'])->toContain('Authorization: Bearer secret-token')
        ->and(json_decode($requests[0]['body'], true))->toBe(['purge_everything' => true]);
});

it('throws on a rate limited or refused Cloudflare purge so the queue retries it', function (int $status, string $body): void {
    configureCloudflare();
    $requests = [];

    Mage::getModel('edgecache/adapter_cloudflare')
        ->setHttpClient(recordingClient($requests, $body, $status))
        ->purgeTags(['p1']);
})->with([
    'rate limited' => [429, '{"success":false,"errors":[{"code":971,"message":"Please wait"}]}'],
    'server error' => [502, 'Bad gateway'],
    'not accepted' => [200, '{"success":false,"errors":[{"code":1012,"message":"Request must contain one of purge_everything, files, tags"}]}'],
])->throws(RuntimeException::class);

it('passes on how long Cloudflare asks to wait after a 429', function (): void {
    configureCloudflare();
    $requests = [];

    try {
        Mage::getModel('edgecache/adapter_cloudflare')
            ->setHttpClient(recordingClient($requests, '{"success":false}', 429, ['Retry-After: 120']))
            ->purgeTags(['p1']);
        $retryAfter = null;
    } catch (Icecube_EdgeCache_Model_Adapter_RateLimitedException $e) {
        $retryAfter = $e->retryAfter;
    }

    expect($retryAfter)->toBe(120);
});

it('refuses to purge Cloudflare without credentials', function (): void {
    $requests = [];

    Mage::getModel('edgecache/adapter_cloudflare')->setHttpClient(recordingClient($requests))->purgeAll();
})->throws(RuntimeException::class, 'zone ID and an API token');

it('emits CDN-Cache-Control and Cache-Tag, or the Cloudflare-only TTL header when configured', function (): void {
    $ttl = new Icecube_EdgeCache_Model_Ttl(86400, 60, 600);
    $adapter = Mage::getModel('edgecache/adapter_cloudflare');

    expect($adapter->getCacheableHeaders($ttl, ['p1', 'c2']))->toBe([
        'CDN-Cache-Control' => 'max-age=86400, stale-while-revalidate=60, stale-if-error=600',
        'Cache-Tag' => 'p1,c2',
    ]);

    currentStore()->setConfig(Cloudflare::XML_PATH_HEADER, 'Cloudflare-CDN-Cache-Control');
    expect($adapter->getUncacheableHeaders())->toBe(['Cloudflare-CDN-Cache-Control' => 'no-store']);
});

it('tells Varnish not to store an uncacheable response', function (): void {
    // Core gives a 301 max-age=86400 with no Set-Cookie, and an untagged 301 could never be purged.
    expect(Mage::getModel('edgecache/adapter_varnish')->getUncacheableHeaders())->toBe(['CDN-Cache-Control' => 'no-store']);
});

it('purges every Varnish node by xkey', function (): void {
    currentStore()->setConfig(Varnish::XML_PATH_NODES, "http://10.0.0.5:6081\nhttp://10.0.0.6:6081/");
    currentStore()->setConfig(Varnish::XML_PATH_SOFT_PURGE, '1');
    $requests = [];

    Mage::getModel('edgecache/adapter_varnish')->setHttpClient(recordingClient($requests, ''))->purgeTags(['p1', 'c2']);

    expect(array_column($requests, 'url'))->toBe(['http://10.0.0.5:6081/', 'http://10.0.0.6:6081/'])
        ->and($requests[0]['method'])->toBe('PURGE')
        ->and($requests[0]['headers'])->toContain('xkey: p1 c2')
        ->and($requests[0]['headers'])->toContain('X-Soft-Purge: 1');
});

it('bans every store host on a Varnish purge-all', function (): void {
    currentStore()->setConfig(Varnish::XML_PATH_NODES, 'http://10.0.0.5:6081');
    $requests = [];

    Mage::getModel('edgecache/adapter_varnish')->setHttpClient(recordingClient($requests, ''))->purgeAll();

    expect($requests)->not->toBeEmpty()
        ->and($requests[0]['method'])->toBe('BAN')
        ->and($requests[0]['headers'])->toContain('Host: localhost')
        ->and($requests[0]['headers'])->toContain('X-Ban-Url: .');
});

it('falls back to the registered noop adapter by default', function (): void {
    expect(Mage::helper('edgecache')->getAdapter())->toBeInstanceOf(Icecube_EdgeCache_Model_Adapter_Noop::class);
});

it('refuses an adapter code nobody registered', function (): void {
    currentStore()->setConfig(Icecube_EdgeCache_Helper_Data::XML_PATH_ADAPTER, 'fastly');

    Mage::helper('edgecache')->getAdapter();
})->throws(RuntimeException::class, 'not registered');
