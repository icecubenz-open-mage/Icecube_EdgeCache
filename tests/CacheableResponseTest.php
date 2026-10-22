<?php

declare(strict_types=1);

use Icecube\EdgeCache\Tests\Support\HttpServer;
use Icecube\EdgeCache\Tests\Support\Catalogue;
use Symfony\Contracts\HttpClient\ResponseInterface;

beforeEach(function (): void {
    Catalogue::ensure();
    HttpServer::start(['edgecache/general/enabled' => '1']);
});

afterEach(function (): void {
    removeResizeFixture();
});

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
 * data-params blob (HTML-escaped) or a /form_key/ URL segment.
 */
function containsRealFormKey(string $body): bool
{
    return preg_match('#(form_key(&quot;:&quot;|/|"\s+value=")|formKey:\s*")(?!__EDGEFK__)[A-Za-z0-9]{16}#', $body) === 1;
}

it('serves an allowlisted page without a session, cacheable and tagged', function (string $path, string $tagType, string $idKey): void {
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
        ->and($body)->toContain(Icecube_EdgeCache_Helper_Data::FORM_KEY_SENTINEL)
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

it('leaves the response private when a request cookie can change the page', function (string $cookie): void {
    $response = HttpServer::request('GET', '/edgecache-product/', ['Cookie' => $cookie]);

    expect($response->getStatusCode())->toBe(200)
        ->and(edgeHeader($response, 'Cache-Control')[0] ?? '')->toContain('private')
        ->and(edgeHeader($response, 'X-Edge-Cache-Control'))->toBe(['no-store'])
        ->and(edgeHeader($response, 'X-Edge-Tags'))->toBe([])
        ->and($response->getContent(false))->not->toContain(Icecube_EdgeCache_Helper_Data::FORM_KEY_SENTINEL);
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

it('still hands a refused page to edgecache.js, so its forms get a real key', function (): void {
    // The key it rendered with belongs to no session, so a POST carrying it would fail.
    $body = HttpServer::request('GET', '/edgecache-product/?___store=default')->getContent(false);

    expect($body)->toContain(Icecube_EdgeCache_Helper_Data::FORM_KEY_SENTINEL)
        ->and(containsRealFormKey($body))->toBeFalse();
});

/**
 * A signed core/index/resize query for a small PNG, as Mage_Catalog_Helper_Image builds
 * one when the resized file doesn't exist yet.
 */
function resizeQuery(): string
{
    $media = Mage::getSingleton('catalog/product_media_config')->getBaseMediaPath();
    if (!is_dir($media . '/e/d')) {
        mkdir($media . '/e/d', recursive: true);
    }
    imagepng(imagecreatetruecolor(40, 40), $media . '/e/d/edgecache.png');

    return Maho::signImageResizeRequest(resizeModel()->getTransformParams(), Mage::getEncryptionKeyAsHex());
}

function resizeModel(): Mage_Catalog_Model_Product_Image
{
    return Mage::getModel('catalog/product_image')
        ->setDestinationSubdir('small_image')
        ->setWidth(20)
        ->setHeight(20)
        ->setBaseFile('/e/d/edgecache.png');
}

/**
 * The source image and the copy the resize request wrote, which live in the store's real media folder.
 */
function removeResizeFixture(): void
{
    $source = Mage::getSingleton('catalog/product_media_config')->getBaseMediaPath() . '/e/d/edgecache.png';
    if (!is_file($source)) {
        return;
    }
    $resized = resizeModel()->getNewFile();
    foreach ([$resized, $source] as $file) {
        if (is_string($file) && is_file($file)) {
            unlink($file);
        }
    }
    foreach ([dirname($source), dirname($source, 2)] as $dir) {
        if (is_dir($dir) && glob($dir . '/*') === []) {
            rmdir($dir);
        }
    }
}

it('serves a resized product image without a session, cacheable at the edge', function (): void {
    // A cached page keeps the resize URL it was rendered with, so every visitor fetches it.
    $response = HttpServer::request('GET', '/core/index/resize/?' . resizeQuery());

    expect($response->getStatusCode())->toBe(200)
        ->and(edgeHeader($response, 'Content-Type')[0] ?? '')->toStartWith('image/')
        ->and(edgeHeader($response, 'Set-Cookie'))->toBe([])
        ->and(edgeHeader($response, 'Cache-Control'))->toBe(['max-age=86400, public'])
        ->and(edgeHeader($response, 'X-Edge-Cache-Control'))->toBe(['max-age=86400, stale-while-revalidate=86400, stale-if-error=604800'])
        ->and(edgeHeader($response, 'X-Edge-Tags'))->toBe([]);
});

it('serves a resized image without a session to a visitor holding a bypass cookie', function (string $cookie): void {
    // The image is the same for everyone, and a session holder's parallel image requests
    // would otherwise queue on the session lock.
    $response = HttpServer::request('GET', '/core/index/resize/?' . resizeQuery(), ['Cookie' => $cookie]);

    expect($response->getStatusCode())->toBe(200)
        ->and(edgeHeader($response, 'Set-Cookie'))->toBe([])
        ->and(edgeHeader($response, 'Cache-Control'))->toBe(['max-age=86400, public'])
        ->and(edgeHeader($response, 'X-Edge-Cache-Control'))->toBe(['max-age=86400, stale-while-revalidate=86400, stale-if-error=604800']);
})->with('bypass cookies');

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

it('loads edgecache.js without blocking render, with an inline guard in the head until it runs', function (): void {
    $body = HttpServer::request('GET', '/')->getContent(false);
    $head = strstr($body, '</head>', true);

    expect($head)->toBeString()
        ->and(preg_match('#<script src="[^"]*/js/edgecache\.js"[^>]*\bdefer\b#', (string) $head))->toBe(1)
        ->and(preg_match('#<script src="[^"]*/js/edgecache\.js"(?![^>]*\bdefer\b)#', $body))->toBe(0)
        ->and((string) $head)->toContain('window.EDGECACHE_HELD')
        ->and((string) $head)->toContain('window.EDGECACHE_STATE_URL')
        // If edgecache.js never runs, the guard lets held actions through so core can complain.
        ->and((string) $head)->toContain("addEventListener('DOMContentLoaded'");
});

it('answers HEAD like GET', function (): void {
    $response = HttpServer::request('HEAD', '/edgecache-product/');

    expect($response->getStatusCode())->toBe(200)
        ->and(edgeHeader($response, 'Set-Cookie'))->toBe([])
        ->and(edgeHeader($response, 'Cache-Control'))->toBe(['max-age=0, must-revalidate, public'])
        ->and(edgeHeader($response, 'X-Edge-Cache-Control'))->toBe(['max-age=86400, stale-while-revalidate=86400, stale-if-error=604800']);
});

it('does not cache a redirect from an allowlisted action', function (): void {
    // A search with exactly one match redirects to that product.
    $response = HttpServer::request('GET', '/catalogsearch/result/?q=' . rawurlencode('Edge Cache Product'));

    expect($response->getStatusCode())->toBe(302)
        ->and(edgeHeader($response, 'Cache-Control')[0] ?? '')->not->toContain('public')
        ->and(edgeHeader($response, 'X-Edge-Cache-Control'))->toBe(['no-store']);
});

it('puts the sentinel only where edgecache.js swaps it for the key', function (string $path): void {
    $body = HttpServer::request('GET', $path)->getContent(false);

    // The places edgecache.js swaps: a form_key input's value, the minicart script, and a
    // form_key in an href, action, onclick or data-params attribute (KEY_ATTRIBUTES there).
    $allowed = '#(?:name="form_key"[^>]*value="|formKey:\s*"|\s(?:href|action|onclick|data-params)="[^"]*form_key(?:/|=|&quot;:&quot;))$#';
    $offset = 0;
    $count = 0;
    while (($at = strpos($body, Icecube_EdgeCache_Helper_Data::FORM_KEY_SENTINEL, $offset)) !== false) {
        $before = substr($body, max(0, $at - 200), min($at, 200));
        expect(preg_match($allowed, $before))->toBe(1, 'Sentinel in an unexpected place: ' . substr($before, -80));
        $offset = $at + 1;
        $count++;
    }

    expect($count)->toBeGreaterThan(0);
})->with(['/', '/edgecache-category/', '/edgecache-product/']);

it('does not cache a page that echoes the sentinel back from the request', function (): void {
    // edgecache.js would otherwise put the visitor's key into the search box, then the URL and Referer.
    $response = HttpServer::request('GET', '/catalogsearch/result/?q=' . Icecube_EdgeCache_Helper_Data::FORM_KEY_SENTINEL);

    expect($response->getStatusCode())->toBe(200)
        ->and(edgeHeader($response, 'X-Edge-Cache-Control'))->toBe(['no-store']);
});

it('never marks a POST cacheable', function (string $path): void {
    $response = HttpServer::request('POST', $path, body: ['q' => 'x']);

    expect(edgeHeader($response, 'Cache-Control')[0] ?? '')->not->toContain('public')
        ->and(edgeHeader($response, 'X-Edge-Cache-Control'))->not->toContain('max-age')
        ->and(edgeHeader($response, 'X-Edge-Tags'))->toBe([]);
})->with(['/', '/edgecache-product/', '/catalogsearch/result/']);

it('starts a session as usual on a page that is not allowlisted', function (): void {
    $response = HttpServer::request('GET', '/customer/account/login/');
    $cookies = implode("\n", edgeHeader($response, 'Set-Cookie'));

    expect($response->getStatusCode())->toBe(200)
        ->and(edgeHeader($response, 'Cache-Control')[0] ?? '')->toContain('private')
        ->and(edgeHeader($response, 'X-Edge-Cache-Control'))->toBe(['no-store'])
        ->and($cookies)->toContain('maho_session=')
        ->and($cookies)->toMatch('/edgecache_s=1;(?!.*HttpOnly)/i');
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
