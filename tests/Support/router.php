<?php

/**
 * Router for PHP's built-in server when the tests drive Maho over HTTP (see HttpServer).
 *
 * Mirrors public/index.php, except that it can point Maho's cache at its own directory
 * and run a given store. Config overrides passed to the server as MAHO_CONFIG__ variables
 * end up in the config cache, and a shared cache would hand them to the test process too.
 */

declare(strict_types=1);

// HttpServer starts PHP's built-in server in the project root.
$rootDir = (string) getcwd();
$publicDir = $rootDir . '/public';

// Mage::setRoot() derives the app root from the working directory, as it does under php-fpm.
chdir($publicDir);

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = is_string($uri) ? parse_url($uri, PHP_URL_PATH) : null;
if (is_string($path) && $path !== '/' && is_file($publicDir . $path)) {
    return false;
}

define('MAHO_ROOT_DIR', $rootDir);
define('MAHO_PUBLIC_DIR', $publicDir);

require MAHO_ROOT_DIR . '/vendor/autoload.php';

$cacheDir = getenv('EDGECACHE_TEST_CACHE_DIR');
Mage::run('', 'store', is_string($cacheDir) && $cacheDir !== '' ? ['cache' => ['cache_dir' => $cacheDir]] : []);

return true;
