<?php

namespace App\Support;

use GrahamCampbell\GuzzleFactory\GuzzleFactory;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Utils;
use Psr\Http\Message\RequestInterface;

/**
 * In local dev, deployed app domains (e.g. *.127.0.0.1.nip.io) resolve to an
 * IP that's only reachable from the browser -- from inside this container that
 * same IP is its own loopback, so a request silently hits this app instead of
 * the actual deployed one. When DEV_INGRESS_GATEWAY is set (e.g.
 * host.docker.internal, which reaches the host machine where the local
 * cluster's ingress ports are published), the connection target of just the
 * request's host is overridden, leaving the Host header/TLS SNI untouched.
 */
class DevIngress
{
    /**
     * A curl CURLOPT_RESOLVE entry ("host:port:ip") for the url, or null when
     * there is no gateway configured (or it can't be resolved).
     */
    public static function resolve(string $url): ?string
    {
        $gateway = config('services.dev_ingress_gateway');

        if (! $gateway) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! $host) {
            return null;
        }

        $port = parse_url($url, PHP_URL_PORT) ?? (parse_url($url, PHP_URL_SCHEME) === 'https' ? 443 : 80);
        $ip = filter_var($gateway, FILTER_VALIDATE_IP) ? $gateway : gethostbyname($gateway);

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        return "{$host}:{$port}:{$ip}";
    }

    /**
     * Whether the host is a name that points at this machine's own loopback,
     * which is what a deployed app's dev domain does.
     */
    public static function pointsAtLoopback(string $host): bool
    {
        return str_starts_with(gethostbyname($host), '127.');
    }

    /**
     * A Guzzle handler stack for clients that don't go through Integration
     * (the uptime monitor's): the factory's usual stack, with the same rewrite
     * applied to the hosts that point at loopback and every other request left
     * alone. Null when no gateway is set.
     */
    public static function guzzleHandler(): ?HandlerStack
    {
        if (! config('services.dev_ingress_gateway')) {
            return null;
        }

        $inner = Utils::chooseHandler();

        return GuzzleFactory::innerHandler(static function (RequestInterface $request, array $options) use ($inner) {
            $host = $request->getUri()->getHost();

            if ($host !== '' && self::pointsAtLoopback($host) && $resolve = self::resolve((string) $request->getUri())) {
                $options['curl'][CURLOPT_RESOLVE] = [...($options['curl'][CURLOPT_RESOLVE] ?? []), $resolve];
            }

            return $inner($request, $options);
        });
    }
}
