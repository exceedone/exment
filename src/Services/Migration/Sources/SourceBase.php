<?php

namespace Exceedone\Exment\Services\Migration\Sources;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Shared plumbing for the sources that talk to a live API.
 *
 * The parts every one of them gets wrong the first time live here: paging that
 * stops, a rate limit that is waited out instead of hit again, and a failure
 * that says which credential is wrong rather than throwing a Guzzle exception
 * at somebody running a migration at 2am.
 */
abstract class SourceBase implements SourceInterface
{
    /** How many times a request is retried before the migration gives up. */
    public const MAX_ATTEMPTS = 4;

    /** Seconds to wait after the first failure; doubles each further attempt. */
    public const BACKOFF_SECONDS = 2;

    /** Below this many calls left in the window, wait for it to reset. */
    public const RATE_LIMIT_FLOOR = 5;

    /** Refuse to page forever if a source keeps saying "there is more". */
    public const MAX_PAGES = 10000;

    /** @var array<string, mixed> */
    protected $config;

    /** @var string[] */
    protected $notes = [];

    /** @var int */
    protected $calls = 0;

    /** @var float total seconds spent waiting on rate limits */
    protected $waited = 0;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Anything worth telling the operator that is not an error: a stream that
     * was skipped, a rate limit that was waited out, a field that was empty.
     *
     * @return string[]
     */
    public function notes(): array
    {
        return $this->notes;
    }

    /**
     * How many HTTP calls this run has made. Printed at the end, because on a
     * metered API that number is the cost of the run.
     *
     * @return int
     */
    public function calls(): int
    {
        return $this->calls;
    }

    /**
     * @return float
     */
    public function waited(): float
    {
        return $this->waited;
    }

    /**
     * @param string $message
     * @return void
     */
    protected function note(string $message)
    {
        if (!in_array($message, $this->notes)) {
            $this->notes[] = $message;
        }
    }

    /**
     * A setting, from the config passed in or else the environment.
     *
     * Credentials come from the environment on purpose. Putting an API key in
     * the database means it has to be readable to be edited, and then every
     * backup carries a live credential for somebody else's system.
     *
     * @param string $key
     * @param string|null $env
     * @param mixed $default
     * @return mixed
     */
    protected function setting(string $key, ?string $env = null, $default = null)
    {
        $value = array_get($this->config, $key);
        if (!is_nullorempty($value)) {
            return $value;
        }
        if ($env) {
            $value = env($env);
            if (!is_nullorempty($value)) {
                return $value;
            }
        }
        return $default;
    }

    /**
     * One GET, retried sensibly, returning the decoded body.
     *
     * @param string $url
     * @param array<string, mixed> $query
     * @param array<string, mixed> $options headers, auth, timeout
     * @return array<string, mixed>|null null when the source said "nothing here"
     * @throws \Exception on an unrecoverable answer
     */
    protected function get(string $url, array $query = [], array $options = [])
    {
        $attempt = 0;

        while (true) {
            $attempt++;
            $this->calls++;

            try {
                $request = Http::timeout(array_get($options, 'timeout', 60))
                    ->withHeaders(array_get($options, 'headers', []));

                if ($auth = array_get($options, 'basic')) {
                    $request = $request->withBasicAuth($auth[0], $auth[1]);
                }

                $response = $request->get($url, $query);
            } catch (\Throwable $e) {
                // could not reach it at all - worth retrying, the far end may
                // be restarting behind a load balancer
                if ($attempt >= static::MAX_ATTEMPTS) {
                    throw new \Exception('could not reach ' . $this->host($url) . ': ' . $e->getMessage());
                }
                $this->backOff($attempt);
                continue;
            }

            $status = $response->status();

            // a wrong credential never becomes right by asking again
            if (in_array($status, [401, 403])) {
                throw new \Exception(sprintf(
                    '%s refused the credentials (HTTP %d). Check the api key / user and that it may read this data.',
                    $this->host($url),
                    $status
                ));
            }

            if ($status == 404) {
                return null;
            }

            if ($status == 429) {
                $wait = $this->retryAfter($response);
                $this->note(sprintf('rate limited by %s, waited %ds', $this->host($url), $wait));
                $this->sleep($wait);
                // a rate limit is not a failure, so it does not spend an attempt
                $attempt--;
                continue;
            }

            if ($status >= 500) {
                if ($attempt >= static::MAX_ATTEMPTS) {
                    throw new \Exception(sprintf('%s kept answering HTTP %d', $this->host($url), $status));
                }
                $this->backOff($attempt);
                continue;
            }

            if ($status >= 400) {
                throw new \Exception(sprintf(
                    '%s rejected the request (HTTP %d): %s',
                    $this->host($url),
                    $status,
                    Str::limit(strval($response->body()), 300)
                ));
            }

            $this->throttle($response);

            $body = $response->json();

            return is_array($body) ? $body : [];
        }
    }

    /**
     * Wait out the window before it is hit, rather than after.
     *
     * Backlog says how many calls are left; spending the last few and taking a
     * 429 costs a full window, while pausing at the floor costs seconds.
     *
     * @param \Illuminate\Http\Client\Response $response
     * @return void
     */
    protected function throttle($response)
    {
        $remaining = $response->header('X-RateLimit-Remaining');
        if (!is_numeric($remaining) || intval($remaining) > static::RATE_LIMIT_FLOOR) {
            return;
        }

        $reset = $response->header('X-RateLimit-Reset');
        $wait = is_numeric($reset) ? intval($reset) - time() : 60;
        $wait = max(1, min(120, $wait));

        $this->note(sprintf('rate limit nearly spent (%s left), waited %ds', $remaining, $wait));
        $this->sleep($wait);
    }

    /**
     * @param \Illuminate\Http\Client\Response $response
     * @return int
     */
    protected function retryAfter($response): int
    {
        $after = $response->header('Retry-After');
        if (is_numeric($after)) {
            return max(1, min(300, intval($after)));
        }

        $reset = $response->header('X-RateLimit-Reset');
        if (is_numeric($reset)) {
            return max(1, min(300, intval($reset) - time()));
        }

        return 60;
    }

    /**
     * @param int $attempt
     * @return void
     */
    protected function backOff(int $attempt)
    {
        $this->sleep(static::BACKOFF_SECONDS * pow(2, $attempt - 1));
    }

    /**
     * @param int|float $seconds
     * @return void
     */
    protected function sleep($seconds)
    {
        $seconds = max(0, $seconds);
        $this->waited += $seconds;
        if ($seconds > 0) {
            sleep(intval(ceil($seconds)));
        }
    }

    /**
     * @param string $url
     * @return string
     */
    protected function host(string $url): string
    {
        return strval(parse_url($url, PHP_URL_HOST) ?: $url);
    }
}
