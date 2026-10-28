<?php

namespace Exceedone\Exment\Services\Meili;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use Meilisearch\Client;
use Psr\Http\Message\RequestInterface;

/**
 * Create a Meilisearch client from config/meilisearch.php.
 *
 * Pass a Guzzle client with short timeouts: if Meili is down, the request fails fast so the
 * controller falls back to MySQL immediately (no hanging the user for the ~default few-dozen seconds).
 *
 * Short is not the same as free. One connect timeout is the floor for every
 * single call, and the paths here make several: a search results page asks for
 * a distribution, the index settings and the owner marker, and a bulk import
 * runs one sync per record. Against a host that is really down each of those
 * waits the full connect timeout again, so a host that has just failed to
 * answer is remembered for a few seconds and the calls made inside that window
 * fail without touching the network.
 */
class MeiliClientFactory
{
    /** Default seconds to treat a host that could not be reached as still down. */
    public const UNREACHABLE_BACKOFF = 10;

    /** @var array<string,int> host => when connecting to it last failed, per process */
    private static array $unreachableAt = [];

    public static function make(): Client
    {
        $stack = HandlerStack::create();
        $stack->push(self::breaker(), 'meili_unreachable_breaker');

        $http = new GuzzleClient([
            'handler'         => $stack,
            'connect_timeout' => (float) config('meilisearch.connect_timeout', 1.0),
            'timeout'         => (float) config('meilisearch.timeout', 5.0),
        ]);

        return new Client(
            config('meilisearch.host'),
            config('meilisearch.key'),
            $http
        );
    }

    /**
     * Guzzle middleware: skip the call while this host is known to be down, and
     * keep track of which hosts those are.
     */
    private static function breaker(): callable
    {
        return function (callable $handler): callable {
            return function (RequestInterface $request, array $options) use ($handler) {
                $host = self::hostKey($request);

                $failedAt = self::$unreachableAt[$host] ?? null;
                if ($failedAt !== null) {
                    if (!self::probeIsDue($failedAt, self::backoff(), time())) {
                        return Create::rejectionFor(new ConnectException(
                            sprintf('Meilisearch at %s was unreachable less than %ds ago, not retrying yet', $host, self::backoff()),
                            $request
                        ));
                    }
                    unset(self::$unreachableAt[$host]);
                }

                return $handler($request, $options)->then(
                    function ($response) use ($host) {
                        unset(self::$unreachableAt[$host]);

                        return $response;
                    },
                    function ($reason) use ($host) {
                        // Only a failure to connect: an HTTP error means the
                        // server answered, and a slow answer is not an outage.
                        if ($reason instanceof ConnectException) {
                            self::$unreachableAt[$host] = time();
                        }

                        return Create::rejectionFor($reason);
                    }
                );
            };
        };
    }

    /**
     * Whether a host last seen unreachable at $failedAt is worth another call.
     * A backoff of 0 disables the wait, so every call goes out.
     */
    public static function probeIsDue(int $failedAt, int $backoff, int $now): bool
    {
        return $backoff === 0 || $now - $failedAt >= $backoff;
    }

    private static function backoff(): int
    {
        return max(0, (int) config('meilisearch.unreachable_backoff', self::UNREACHABLE_BACKOFF));
    }

    private static function hostKey(RequestInterface $request): string
    {
        $uri = $request->getUri();

        return $uri->getHost() . ':' . ($uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80));
    }

    /** Forget which hosts are down (tests, and after the host setting changes). */
    public static function forgetUnreachable(): void
    {
        self::$unreachableAt = [];
    }

    /** Whether this process currently considers $host down (diagnostics, tests). */
    public static function isHostKnownDown(string $host): bool
    {
        $failedAt = self::$unreachableAt[$host] ?? null;

        return $failedAt !== null && !self::probeIsDue($failedAt, self::backoff(), time());
    }
}
