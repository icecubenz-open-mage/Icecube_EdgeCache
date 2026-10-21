<?php

/**
 * Base for adapters that purge over HTTP.
 *
 * SPDX-FileCopyrightText: 2026 Icecube
 * SPDX-License-Identifier: OSL-3.0
 * @package Icecube_EdgeCache
 */

declare(strict_types=1);

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

abstract class Icecube_EdgeCache_Model_Adapter_AbstractHttp implements Icecube_EdgeCache_Model_Adapter_AdapterInterface
{
    private ?HttpClientInterface $httpClient = null;

    public function setHttpClient(HttpClientInterface $httpClient): static
    {
        $this->httpClient = $httpClient;

        return $this;
    }

    protected function getHttpClient(): HttpClientInterface
    {
        return $this->httpClient ??= HttpClient::create(['timeout' => 15]);
    }

    /**
     * A 429 or 5xx must reach the queue as an exception, or the purge is lost instead of retried.
     */
    protected function assertSuccessful(ResponseInterface $response, string $operation): void
    {
        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return;
        }

        $message = sprintf('%s failed with HTTP %d: %s', $operation, $status, substr($response->getContent(false), 0, 500));
        $retryAfter = $status === 429 ? $this->getRetryAfter($response) : null;
        if ($retryAfter !== null) {
            throw new Icecube_EdgeCache_Model_Adapter_RateLimitedException($message, $retryAfter);
        }

        throw new RuntimeException($message);
    }

    /**
     * Seconds from a Retry-After header, which may also be an HTTP date.
     */
    private function getRetryAfter(ResponseInterface $response): ?int
    {
        $value = trim($response->getHeaders(false)['retry-after'][0] ?? '');
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return (int) $value;
        }
        $time = strtotime($value);

        return $time === false ? null : max(0, $time - time());
    }

    protected function getConfig(string $path): string
    {
        $value = Mage::getStoreConfig($path);

        return is_string($value) ? trim($value) : '';
    }
}
