<?php

declare(strict_types=1);

namespace Icecube\EdgeCache\Tests\Support;

use Composer\InstalledVersions;
use RuntimeException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Serves the project's test install over real HTTP, for behaviour that only shows on the wire.
 *
 * Under the CLI, setcookie() and header() are no-ops and headers_list() is always empty, so
 * an in-process dispatch cannot prove that a response carries no Set-Cookie. This runs PHP's
 * built-in server on a free port, the way Maho's own Api and Browser suites do.
 *
 * Requests are sent with the Host the store was installed with (localhost), because
 * web/url/redirect_to_base redirects any other host:port to the base URL.
 */
final class HttpServer
{
    private const HOST = 'localhost';

    private static ?Process $process = null;
    private static ?HttpClientInterface $client = null;
    private static string $baseUrl = '';

    /** @var array<string, string> */
    private static array $config = [];

    private static bool $stopRegistered = false;

    /**
     * Keeps one server running for as long as callers ask for the same config, and restarts it
     * with a fresh cache when the config changes.
     *
     * @param array<string, string> $config config paths for the default store view, server only
     */
    public static function start(array $config = []): void
    {
        if (self::$process?->isRunning() === true && self::$config === $config) {
            return;
        }
        self::stop();
        self::$config = $config;

        $root = self::projectRoot();
        $port = self::freePort();
        $cacheDir = $root . '/var/cache-http';
        self::removeDirectory($cacheDir);

        $env = ['EDGECACHE_TEST_CACHE_DIR' => $cacheDir, 'PHP_CLI_SERVER_WORKERS' => '4'];
        foreach ($config as $path => $value) {
            // Store scope: Maho applies environment overrides after copying the default scope
            // into each store, so a DEFAULT override never reaches Mage::getStoreConfig().
            $env['MAHO_CONFIG__STORES__DEFAULT__' . strtoupper(str_replace('/', '__', $path))] = $value;
        }

        self::$process = new Process(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', 'public', __DIR__ . '/router.php'],
            $root,
            $env,
        );
        self::$process->setTimeout(null);
        self::$process->start();
        if (!self::$stopRegistered) {
            register_shutdown_function(self::stop(...));
            self::$stopRegistered = true;
        }

        self::$baseUrl = 'http://127.0.0.1:' . $port;
        self::waitUntilListening($port);
    }

    public static function stop(): void
    {
        self::$process?->stop(3);
        self::$process = null;
    }

    /**
     * Never follows redirects, so a test sees the response Maho actually sent.
     *
     * @param array<string, string> $headers
     * @param array<string, string> $body sent form-encoded
     */
    public static function request(string $method, string $path, array $headers = [], array $body = []): ResponseInterface
    {
        if (self::$baseUrl === '') {
            throw new RuntimeException('HttpServer::start() has not been called');
        }

        self::$client ??= HttpClient::create(['max_redirects' => 0, 'timeout' => 60]);
        $options = ['headers' => ['Host' => self::HOST, ...$headers]];
        if ($body !== []) {
            $options['body'] = $body;
        }

        return self::$client->request($method, self::$baseUrl . $path, $options);
    }

    /**
     * The project this package is installed into, wherever the package itself lives.
     */
    private static function projectRoot(): string
    {
        $root = realpath(InstalledVersions::getRootPackage()['install_path']);
        if ($root === false) {
            throw new RuntimeException('Could not find the project root');
        }

        return $root;
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new RuntimeException('Could not find a free port');
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private static function waitUntilListening(int $port): void
    {
        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
            if ($connection !== false) {
                fclose($connection);
                return;
            }
            usleep(100_000);
        }

        throw new RuntimeException('PHP built-in server did not start: ' . self::$process?->getErrorOutput());
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }
        rmdir($dir);
    }
}
