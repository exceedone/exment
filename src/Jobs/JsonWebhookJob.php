<?php

namespace Exceedone\Exment\Jobs;

use Exceedone\Exment\Services\Notify\JsonWebhook;
use Exceedone\Exment\Services\WebhookUrlGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;

/**
 * Post a notification as JSON (JsonWebhook, ISS02912).
 *
 * Tried 3 times: by the queue worker a minute and five minutes apart, or at once a second or
 * two apart when there is no worker (QUEUE_CONNECTION=sync: after the answer of the save). Not
 * sent at last: a bell to the user who made the notification (JsonWebhook::failed()).
 */
class JsonWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** @var int */
    public $tries = 3;

    /** @var array<int, int> */
    public $backoff = [60, 300];

    /** @var int */
    public $timeout = 60;

    /**
     * @param string|null $secret as Notify keeps it (encrypted)
     */
    public function __construct(
        public string $url,
        public string $body,
        public ?string $secret,
        public ?int $notifyId,
        public string $delivery
    ) {
        // a worker may run it at once: the record is committed first (without a worker it is sent
        // here, as Slack and Teams are)
        if (strval(config('queue.default')) !== 'sync') {
            $this->afterCommit();
        }
    }

    public function handle(): void
    {
        // a worker: it tries again itself (backoff), and failed() tells at last
        if (isset($this->job) && $this->job->getConnectionName() !== 'sync') {
            $result = $this->attempt();
            if ($result === null) {
                return;
            }
            if (!$result['retry']) {
                $this->fail(new \RuntimeException($result['error']));
                return;
            }
            throw new \RuntimeException($result['error']);
        }

        // no worker: here, a little later each time
        $waits = array_values((array)config('exment.notify_webhook_retry_seconds', [1, 2]));
        $error = '';
        for ($i = 0; $i < $this->tries; $i++) {
            $result = $this->attempt();
            if ($result === null) {
                return;
            }
            $error = $result['error'];
            if (!$result['retry'] || $i === $this->tries - 1) {
                break;
            }
            $wait = intval($waits[$i] ?? end($waits));
            if ($wait > 0) {
                sleep($wait);
            }
        }
        JsonWebhook::failed($this->notifyId, $this->url, $error);
    }

    /**
     * The last try by the worker failed.
     */
    public function failed(?\Throwable $ex): void
    {
        JsonWebhook::failed($this->notifyId, $this->url, isset($ex) ? $ex->getMessage() : '');
    }

    /**
     * Post once.
     *
     * @return array{retry: bool, error: string}|null null: sent
     */
    protected function attempt(): ?array
    {
        // checked each time: the name may point elsewhere by now
        $ips = WebhookUrlGuard::allowedIps($this->url);
        if ($ips === null) {
            return ['retry' => false, 'error' => exmtrans('notify.message.webhook_url_not_allowed')];
        }

        $timestamp = strval(time());
        $headers = [
            'User-Agent' => 'Exment-Webhook',
            JsonWebhook::HEADER_EVENT => JsonWebhook::EVENT,
            JsonWebhook::HEADER_DELIVERY => $this->delivery,
            JsonWebhook::HEADER_TIMESTAMP => $timestamp,
        ];
        $secret = JsonWebhook::plainSecret($this->secret);
        if (!is_nullorempty($secret)) {
            $headers[JsonWebhook::HEADER_SIGNATURE] = JsonWebhook::signature(strval($secret), $timestamp, $this->body);
        }

        try {
            $response = Http::withHeaders($headers)
                ->withOptions($this->connectOptions($ips))
                ->timeout(10)
                ->connectTimeout(5)
                ->withBody($this->body, 'application/json')
                ->post($this->url);
        } catch (\Throwable $ex) {
            return ['retry' => true, 'error' => $ex->getMessage()];
        }

        if ($response->successful()) {
            return null;
        }
        $status = $response->status();
        // a redirect is not followed (it may lead inside); a client error is not mended by
        // trying again, except a time out and too many requests
        $retry = $status >= 500 || in_array($status, [408, 429], true);
        return ['retry' => $retry, 'error' => 'HTTP ' . $status];
    }

    /**
     * Not redirected, and connected to the address checked (not asked to the name again).
     *
     * @param array<string> $ips
     * @return array<string, mixed>
     */
    protected function connectOptions(array $ips): array
    {
        $options = ['allow_redirects' => false];
        $parts = parse_url($this->url);
        $host = trim(strval($parts['host'] ?? ''), '[]');
        if (empty($ips) || filter_var($host, FILTER_VALIDATE_IP) || !defined('CURLOPT_RESOLVE')) {
            return $options;
        }
        $port = intval($parts['port'] ?? (strtolower(strval($parts['scheme'] ?? '')) === 'https' ? 443 : 80));
        $ip = $ips[0];
        $options['curl'] = [CURLOPT_RESOLVE => [$host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)]];
        return $options;
    }
}
