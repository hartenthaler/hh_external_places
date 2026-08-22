<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\Http;

use Fisharebest\Webtrees\Registry;
use GuzzleHttp\Client;
use Psr\Http\Client\ClientInterface as PsrClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Small HTTP boundary for external read-only providers.
 *
 * webtrees 2.3 supplies a PSR-18 client through its service container. Older
 * installations can still use the Guzzle fallback, without provider classes
 * depending directly on either implementation.
 */
final class HttpTransport
{
    private function __construct(
        private readonly ?PsrClientInterface $psrClient,
        private readonly ?RequestFactoryInterface $requestFactory,
        private readonly ?object $guzzleClient,
    ) {
    }

    public static function default(): self
    {
        if (class_exists(Registry::class) && interface_exists(PsrClientInterface::class) && interface_exists(RequestFactoryInterface::class)) {
            try {
                $container = Registry::container();
                if ($container->has(PsrClientInterface::class) && $container->has(RequestFactoryInterface::class)) {
                    return new self(
                        $container->get(PsrClientInterface::class),
                        $container->get(RequestFactoryInterface::class),
                        null,
                    );
                }
            } catch (Throwable) {
                // Fall through to the legacy client when the container is not
                // bootstrapped (for example in a standalone module test).
            }
        }

        if (class_exists(Client::class)) {
            return new self(null, null, new Client());
        }

        return new self(null, null, null);
    }

    /**
     * Send a bounded GET/POST request and return a PSR-7 response.
     *
     * PSR-18 has no per-request timeout option. The webtrees-provided client
     * owns that policy; the Guzzle fallback keeps the historical timeouts.
     *
     * @param array<string,mixed> $query
     * @param array<string,string> $headers
     */
    public function request(string $method, string $url, array $query = [], array $headers = [], float $timeout = 6.0): ?ResponseInterface
    {
        // Accept the former Guzzle-style options array while callers are
        // migrated incrementally. This also keeps custom module tests small.
        if (array_key_exists('query', $query) || array_key_exists('headers', $query) || array_key_exists('timeout', $query)) {
            $options = $query;
            $query = is_array($options['query'] ?? null) ? $options['query'] : [];
            $headers = is_array($options['headers'] ?? null) ? $options['headers'] : [];
            $timeout = is_numeric($options['timeout'] ?? null) ? (float) $options['timeout'] : $timeout;
        }

        if ($this->psrClient !== null && $this->requestFactory !== null) {
            try {
                if ($query !== []) {
                    $separator = str_contains($url, '?') ? '&' : '?';
                    $url .= $separator . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
                }
                $request = $this->requestFactory->createRequest($method, $url);
                foreach ($headers as $name => $value) {
                    $request = $request->withHeader($name, $value);
                }

                return $this->psrClient->sendRequest($request);
            } catch (Throwable) {
                return null;
            }
        }

        if ($this->guzzleClient === null) {
            return null;
        }

        try {
            return $this->guzzleClient->request($method, $url, [
                'allow_redirects' => false,
                'connect_timeout' => min(3.0, $timeout),
                'headers' => $headers,
                'http_errors' => false,
                'query' => $query,
                'timeout' => $timeout,
            ]);
        } catch (Throwable) {
            return null;
        }
    }
}
