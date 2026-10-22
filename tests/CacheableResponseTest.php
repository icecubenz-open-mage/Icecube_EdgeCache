<?php

declare(strict_types=1);

use Icecube\EdgeCache\Tests\Support\HttpServer;
use Icecube\EdgeCache\Tests\Support\Catalogue;
use Symfony\Contracts\HttpClient\ResponseInterface;

const SERVER_CONFIG = [
    'edgecache/general/enabled' => '1',
    'web/sessionless/enabled' => '1',
    'web/sessionless/routes' => "cms/index/index\ncms/page/view\ncatalog/category/view\ncatalog/product/view\ncatalogsearch/result/index\ncms/index/noRoute",
];

beforeEach(function (): void {
    Catalogue::ensure();
    HttpServer::start(SERVER_CONFIG);
});

afterEach(function (): void {
    removeResizeFixture();
});

// The session cookies, then core's default web/sessionless/cookies
dataset('bypass cookies', [
    'session' => 'maho_session=' . str_repeat('a', 32),
    'legacy session' => 'frontend=' . str_repeat('a', 32),
    'older legacy session' => 'om_frontend=' . str_repeat('a', 32),
    'store view switch' => 'store=default',
    'cookie consent' => 'user_allowed_save_cookie=' . rawurlencode('{"1":1}'),
    'accessibility scan' => 'maho_a11y_scan=' . str_repeat('a', 32),
]);

/**
 * @return list<string>
 */
function edgeHeader(ResponseInterface $response, string $name): array
{
    return $response->getHeaders(false)[strtolower($name)] ?? [];
}

/**
 * A real key is 16 alphanumerics wherever core prints one: an input value, a JSON
 * data-params blob (HTML-escaped), a /form_key/ URL segment or the FORM_KEY global.
 */
function containsRealFormKey(string $body): bool
{
    return preg_match('#(form_key(&quot;:&quot;|/|=|"\s+value=")|formKey:\s*"|FORM_KEY = \')[A-Za-z0-9]{16}#', $body) === 1;
}

it('caches a page core rendered without a session, tagged', function (string $path, string $tagType, string $idKey): void {
    $ids = Catalogue::ensure();
    $tag = $tagType . ($idKey === 'home'
        ? (int) Mage::getModel('cms/page')->load('home', 'identifier')->getId()
        : $ids[$idKey]);

    $response = HttpServer::request('GET', $path);
    $body = $response->getContent(false);

    expect($response->getStatusCode())->toBe(200)
        ->and(edgeHeader($response, 'Set-Cookie'))->toBe([])
        ->and(edgeHeader($response, 'Cache-Control'))->toBe(['max-age=0, must-revalidate, public'])
        ->and(edgeHeader($response, 'X-Edge-Cache-Control'))->toBe(['max-age=86400, stale-while-revalidate=86400, stale-if-error=604800'])
        ->and(explode(',', edgeHeader($response, 'X-Edge-Tags')[0] ?? ''))->toContain($tag)
        ->and($body)->toContain("FORM_KEY = ''")
        ->and(containsRealFormKey($body))->toBeFalse();
})->with([
    'home' => ['/', 'cp', 'home'],
    'CMS page' => ['/' . Catalogue::CMS_PAGE . '/', 'cp', 'page'],
    'category' => ['/edgecache-category/', 'c', 'category'],
    'product' => ['/edgecache-product/', 'p', 'product'],
]);

it('tags a category page with the products it lists', function (): void {
    $ids = Catalogue::ensure();

    $tags = explode(',', edgeHeader(HttpServer::request('GET', '/edgecache-category/'), 'X-Edge-Tags')[0] ?? '');

    expect($tags)->toContain('c' . $ids['category'])->toContain('p' . $ids['product']);
});

it('tags a CMS block on the page when it comes out of the block cache', function (): void {
    $ids = Catalogue::ensure();
    $path = '/' . Catalogue::CMS_PAGE . '/';
    HttpServer::request('GET', $path)->getContent();

    // No CMS block model loads on a block_html hit, so only the block's stored cache tags can say it's there.
    $tags = explode(',', edgeHeader(HttpServer::request('GET', $path), 'X-Edge-Tags')[0] ?? '');

    expect($tags)->toContain('cb' . $ids['block']);
});

it('gives every page with the top menu the coarse category tag', function (): void {
    HttpServer::request('GET', '/')->getContent();

    $tags = explode(',', edgeHeader(HttpServer::request('GET', '/'), 'X-Edge-Tags')[0] ?? '');

    expect($tags)->toContain('c_all');
});

it('gives search results and 404s the short TTL', function (string $path, int $status): void {
    $response = HttpServer::request('GET', $path);

    expect($response->getStatusCode())->toBe($status)
        ->and(edgeHeader($response, 'Set-Cookie'))->toBe([])
        ->and(edgeHeader($response, 'X-Edge-Cache-Control'))->toBe(['max-age=300, stale-while-revalidate=300, stale-if-error=604800']);
})->with([
    'search' => ['/catalogsearch/result/?q=nothing-matches', 200],
    '404' => ['/no-such-page/', 404],
]);

it('does not cache a missing product, which core names catalog/index/noRoute after the forward', function (): void {
    // getFullActionName() keeps the requested route but takes the forwarded controller and
    // action, so neither core's list nor this module ever matches it
    $response = HttpServer::request('GET', '/catalog/product/view/id/999999/');

    expect($response->getStatusCode())->toBe(404)
        ->and(edgeHeader($response, 'Set-Cookie'))->toBe([])
        ->and(edgeHeader($response, 'X-Edge-Cache-Control'))->toBe(['no-store']);
});

it('leaves the response private when a request cookie can change the page', function (string $cookie): void {
    $response = HttpServer::request('GET', '/edgecache-product/', ['Cookie' => $cookie]);

    expect($response->getStatusCode())->toBe(200)
        ->and(edgeHeader($response, 'Cache-Control')[0] ?? '')->toContain('private')
        ->and(edgeHeader($response, 'X-Edge-Cache-Control'))->toBe(['no-store'])
        ->and(edgeHeader($response, 'X-Edge-Tags'))->toBe([]);
})->with('bypass cookies');

it('refuses a sessionless page that sends a cookie anyway', function (): void {
    // Naming the default store view makes core delete the store cookie, a Set-Cookie
    // that nothing in the edge cache predicted.
    $response = HttpServer::request('GET', '/edgecache-product/?___store=default');

    expect($response->getStatusCode())->toBe(200)
        ->and(implode("\n", edgeHeader($response, 'Set-Cookie')))->toContain('store=')
        ->and(edgeHeader($response, 'Cache-Control')[0] ?? '')->not->toContain('public')
        ->and(edgeHeader($response, 'X-Edge-Cache-Control'))->toBe(['no-store'])
        ->and(edgeHeader($response, 'X-Edge-Tags'))->toBe([]);
});

/**
 * A core/index/resize query for a small PNG, signed as releases before 26.11 printed it in
 * pages that the edge may still hold.
 */
function resizeQuery(): string
{
    $media = Mage::getSingleton('catalog/product_media_config')->getBaseMediaPath();
    if (!is_dir($media . '/e/d')) {
        mkdir($media . '/e/d', recursive: true);
    }
    imagepng(imagecreatetruecolor(40, 40), $media . '/e/d/edgecache.png');

    $t = base64_encode(json_encode(['_sourceFile' => '/e/d/edgecache.png', '_width' => 20, '_height' => 20], JSON_THROW_ON_ERROR));

    return 't=' . urlencode($t) . '&s=' . hash_hmac('sha256', $t, Mage::getEncryptionKeyAsHex());
}

/**
 * The source image and the resized copies, which live in the store's real media folder.
 */
function removeResizeFixture(): void
{
    $media = Mage::getSingleton('catalog/product_media_config')->getBaseMediaPath();
    $files = [$media . '/e/d/edgecache.png', ...(glob($media . '/cache/*/*/e/d/edgecache.png*') ?: [])];
    foreach ($files as $file) {
        if (!is_file($file)) {
            continue;
        }
        unlink($file);
        foreach ([dirname($file), dirname($file, 2), dirname($file, 3), dirname($file, 4)] as $dir) {
            if (str_starts_with($dir, $media . '/') && $dir !== $media . '/cache' && is_dir($dir) && glob($dir . '/*') === []) {
                rmdir($dir);
            }
        }
    }
}

it('redirects an old resize URL to the image without a session, and keeps the redirect out of the edge', function (string $cookie): void {
    $response = HttpServer::request('GET', '/core/index/resize/?' . resizeQuery(), $cookie === '' ? [] : ['Cookie' => $cookie]);

    expect($response->getStatusCode())->toBe(301)
        ->and(edgeHeader($response, 'Location')[0] ?? '')->toContain('/media/catalog/product/cache/')
        ->and(edgeHeader($response, 'Set-Cookie'))->toBe([])
        ->and(edgeHeader($response, 'X-Edge-Cache-Control'))->toBe(['no-store']);
})->with([
    'no cookie' => '',
    'session' => 'maho_session=' . str_repeat('a', 32),
    'store view switch' => 'store=default',
]);

it('leaves the image core resizes on a miss as core sends it', function (): void {
    $location = (string) parse_url(edgeHeader(HttpServer::request('GET', '/core/index/resize/?' . resizeQuery()), 'Location')[0] ?? '', PHP_URL_PATH);

    $response = HttpServer::request('GET', $location);

    expect($response->getStatusCode())->toBe(200)
        ->and(edgeHeader($response, 'Content-Type')[0] ?? '')->toStartWith('image/')
        ->and(edgeHeader($response, 'Set-Cookie'))->toBe([])
        ->and(edgeHeader($response, 'Cache-Control'))->toBe(['max-age=31536000, public'])
        ->and(edgeHeader($response, 'X-Edge-Cache-Control'))->toBe([]);
});

it('leaves core answer to an image it cannot create as core sends it', function (): void {
    $response = HttpServer::request('GET', '/media/catalog/product/cache/0123456789ab/20/e/d/no-such-image.png');

    expect($response->getStatusCode())->toBeIn([302, 404])
        ->and(edgeHeader($response, 'Set-Cookie'))->toBe([])
        ->and(edgeHeader($response, 'Cache-Control')[0] ?? '')->toContain('no-store')
        ->and(edgeHeader($response, 'X-Edge-Cache-Control'))->toBe([]);
});

it('does not cache a resize request missing its signature', function (): void {
    $response = HttpServer::request('GET', '/core/index/resize/?t=x');

    expect($response->getStatusCode())->toBe(400)
        ->and(edgeHeader($response, 'Set-Cookie'))->toBe([])
        ->and(edgeHeader($response, 'X-Edge-Cache-Control'))->toBe(['no-store']);
});

it('does not cache a resize request with a bad signature', function (): void {
    $response = HttpServer::request('GET', '/core/index/resize/?' . preg_replace('/s=[0-9a-f]+/', 's=' . str_repeat('0', 64), resizeQuery()));

    expect($response->getStatusCode())->toBe(403)
        ->and(edgeHeader($response, 'Set-Cookie'))->toBe([])
        ->and(edgeHeader($response, 'X-Edge-Cache-Control'))->toBe(['no-store']);
});

it('answers HEAD like GET', function (): void {
    $response = HttpServer::request('HEAD', '/edgecache-product/');

    expect($response->getStatusCode())->toBe(200)
        ->and(edgeHeader($response, 'Set-Cookie'))->toBe([])
        ->and(edgeHeader($response, 'Cache-Control'))->toBe(['max-age=0, must-revalidate, public'])
        ->and(edgeHeader($response, 'X-Edge-Cache-Control'))->toBe(['max-age=86400, stale-while-revalidate=86400, stale-if-error=604800']);
});

it('does not cache a redirect from a sessionless action', function (): void {
    // A search with exactly one match redirects to that product.
    $response = HttpServer::request('GET', '/catalogsearch/result/?q=' . rawurlencode('Edge Cache Product'));

    expect($response->getStatusCode())->toBe(302)
        ->and(edgeHeader($response, 'Cache-Control')[0] ?? '')->not->toContain('public')
        ->and(edgeHeader($response, 'X-Edge-Cache-Control'))->toBe(['no-store']);
});

it('never marks a POST cacheable', function (string $path): void {
    $response = HttpServer::request('POST', $path, body: ['q' => 'x']);

    expect(edgeHeader($response, 'Cache-Control')[0] ?? '')->not->toContain('public')
        ->and(edgeHeader($response, 'X-Edge-Cache-Control'))->not->toContain('max-age')
        ->and(edgeHeader($response, 'X-Edge-Tags'))->toBe([]);
})->with(['/', '/edgecache-product/', '/catalogsearch/result/']);

it('leaves a page off core list alone', function (): void {
    $response = HttpServer::request('GET', '/customer/account/login/');

    expect($response->getStatusCode())->toBe(200)
        ->and(edgeHeader($response, 'Cache-Control')[0] ?? '')->toContain('private')
        ->and(edgeHeader($response, 'X-Edge-Cache-Control'))->toBe(['no-store'])
        ->and(implode("\n", edgeHeader($response, 'Set-Cookie')))->toContain('maho_session=');
});

it('takes Accept out of Vary on cacheable HTML', function (): void {
    $vary = implode(',', edgeHeader(HttpServer::request('GET', '/edgecache-product/'), 'Vary'));

    expect($vary)->not->toContain('Accept');
});

it('keeps markdown negotiated on the HTML URL out of shared caches', function (): void {
    $response = HttpServer::request('GET', '/edgecache-product/', ['Accept' => 'text/markdown']);

    expect(edgeHeader($response, 'Content-Type')[0] ?? '')->toStartWith('text/markdown')
        ->and(edgeHeader($response, 'Cache-Control'))->toBe(['no-store, private'])
        ->and(edgeHeader($response, 'X-Edge-Cache-Control'))->toBe(['no-store'])
        ->and(edgeHeader($response, 'Set-Cookie'))->toBe([]);
});

it('caches nothing while core renders every page with a session', function (string $path): void {
    HttpServer::start(['web/sessionless/enabled' => '0'] + SERVER_CONFIG);

    $response = HttpServer::request('GET', $path);

    expect(edgeHeader($response, 'Cache-Control')[0] ?? '')->not->toContain('public')
        ->and(edgeHeader($response, 'X-Edge-Cache-Control'))->toBe(['no-store'])
        ->and(edgeHeader($response, 'X-Edge-Tags'))->toBe([]);
})->with(['/', '/edgecache-category/', '/edgecache-product/']);

it('caches the pages on core default route list', function (string $path): void {
    HttpServer::start(['edgecache/general/enabled' => '1', 'web/sessionless/enabled' => '1']);

    $response = HttpServer::request('GET', $path);

    expect($response->getStatusCode())->toBe(200)
        ->and(edgeHeader($response, 'Set-Cookie'))->toBe([])
        ->and(edgeHeader($response, 'Cache-Control'))->toBe(['max-age=0, must-revalidate, public']);
})->with(['/', '/edgecache-category/', '/edgecache-product/']);

it('does not cache a 404 under core default route list', function (string $path): void {
    // A missing product forwards from a listed action to an unlisted one
    HttpServer::start(['edgecache/general/enabled' => '1', 'web/sessionless/enabled' => '1']);

    $response = HttpServer::request('GET', $path);

    expect($response->getStatusCode())->toBe(404)
        ->and(edgeHeader($response, 'Cache-Control')[0] ?? '')->not->toContain('public')
        ->and(edgeHeader($response, 'X-Edge-Cache-Control'))->toBe(['no-store']);
})->with(['/no-such-page/', '/catalog/product/view/id/999999/']);
