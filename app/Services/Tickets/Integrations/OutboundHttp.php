<?php

namespace App\Services\Tickets\Integrations;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * The only way integrations talk to the outside. It enforces, for every
 * request: https only, no credentials in the URL, the destination must resolve
 * to public addresses (unless the integration was explicitly allowed to reach
 * internal hosts), the connection is pinned to the address that was checked
 * (so DNS cannot change its answer between the check and the connection),
 * redirects are never followed, and time and response size are limited.
 *
 * Non-success answers become IntegrationExceptions that say whether a retry is
 * worthwhile.
 */
class OutboundHttp
{
    public function __construct(private Factory $http, private HostResolver $resolver) {}

    /**
     * @param  array{headers?: array<string, string>, json?: array<mixed>, body?: string, content_type?: string, query?: array<string, mixed>}  $options
     *
     * @throws IntegrationException
     */
    public function send(string $method, string $url, array $options = [], bool $allowInternal = false): Response
    {
        [$host, $port, $addresses] = $this->check($url, $allowInternal);

        $curl = [];
        if (defined('CURLOPT_RESOLVE') && $addresses !== [] && filter_var($host, FILTER_VALIDATE_IP) === false) {
            $curl[CURLOPT_RESOLVE] = ["{$host}:{$port}:{$addresses[0]}"];
        }

        $limit = config('tickets.integrations.max_response_kb') * 1024;

        $request = $this->http
            ->withHeaders(array_merge(['Accept' => 'application/json', 'User-Agent' => 'KumuliCP-Support'], $options['headers'] ?? []))
            ->timeout(config('tickets.integrations.timeout'))
            ->connectTimeout(config('tickets.integrations.connect_timeout'))
            ->withOptions([
                'allow_redirects' => false,
                'curl' => $curl,
                'on_headers' => function (ResponseInterface $response) use ($limit) {
                    if ((int) $response->getHeaderLine('Content-Length') > $limit) {
                        throw new \RuntimeException('The response is too large.');
                    }
                },
            ]);

        if (array_key_exists('body', $options)) {
            $request = $request->withBody($options['body'], $options['content_type'] ?? 'application/json');
        } elseif (array_key_exists('json', $options)) {
            $request = $request->asJson();
        }

        try {
            $response = $request->send(strtoupper($method), $url, array_filter([
                'query' => $options['query'] ?? null,
                'json' => $options['json'] ?? null,
            ], fn ($value) => $value !== null));
        } catch (ConnectionException $e) {
            throw IntegrationException::retryable('Could not connect: '.$this->short($e->getMessage()));
        } catch (Throwable $e) {
            throw IntegrationException::permanent('The request failed: '.$this->short($e->getMessage()));
        }

        return $this->ensureSuccess($response);
    }

    /**
     * Validate the destination. Returns [host, port, addresses].
     *
     * @return array{0: string, 1: int, 2: list<string>}
     *
     * @throws IntegrationException
     */
    public function check(string $url, bool $allowInternal): array
    {
        $parts = parse_url($url);

        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw IntegrationException::permanent('The address is not a valid URL.');
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme !== 'https' && ! ($scheme === 'http' && config('tickets.integrations.allow_http'))) {
            throw IntegrationException::permanent('Only https:// addresses are allowed.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw IntegrationException::permanent('The address must not contain a user name or password.');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        $addresses = $this->resolver->resolve($host);

        if ($addresses === []) {
            throw IntegrationException::retryable("The host name {$host} could not be resolved.");
        }

        if (! $allowInternal) {
            foreach ($addresses as $address) {
                if (! IpGuard::isPublic($address)) {
                    throw IntegrationException::permanent("{$host} points to a private or internal address, which is not allowed for this integration.");
                }
            }
        }

        return [$host, $port, $addresses];
    }

    private function ensureSuccess(Response $response): Response
    {
        $status = $response->status();

        if ($status >= 200 && $status < 300) {
            return $response;
        }

        $body = $this->short($response->body(), 2000);
        $message = "The other side answered {$status}".($body !== '' ? ': '.$this->short($body, 200) : '.');

        if ($status === 429 || $status === 408 || $status >= 500) {
            throw IntegrationException::retryable($message, $status, $this->retryAfter($response), $body);
        }

        if ($status >= 300 && $status < 400) {
            throw IntegrationException::permanent("The other side answered {$status} (a redirect). Redirects are not followed; use the final address.", $status, $body);
        }

        throw IntegrationException::permanent($message, $status, $body);
    }

    /**
     * Seconds to wait, from a Retry-After header (seconds or a date), capped at six hours.
     */
    private function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        if ($header === '' || $header === null) {
            return null;
        }

        $seconds = is_numeric($header) ? (int) $header : (strtotime($header) ?: time()) - time();

        return max(1, min($seconds, 6 * 3600));
    }

    private function short(string $text, int $length = 300): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length).'…' : $text;
    }
}
