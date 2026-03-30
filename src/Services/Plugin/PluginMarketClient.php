<?php

namespace Exceedone\Exment\Services\Plugin;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Centralised HTTP client factory for Marketplace API calls.
 *
 * All marketplace HTTP requests must go through PluginMarketClient::make() so that:
 *  - Bearer auth (exment.ai_server_api_key) is applied consistently.
 *  - SSL verification is always on (marketplace uses a valid public CA cert).
 *  - Timeouts / retry settings are standardised.
 */
class PluginMarketClient
{
    /**
     * Build a PendingRequest pre-configured for marketplace API calls.
     *
     * The caller is responsible for chaining any additional options
     * (e.g. ->acceptJson(), ->asJson()) and the terminal verb (->get(), ->post(), …).
     *
     * @param  int $timeout        Total request timeout in seconds.
     * @param  int $connectTimeout TCP connect timeout in seconds.
     * @param  int $retry          Number of retries on failure.
     * @param  int $retryDelay     Milliseconds between retries.
     * @return PendingRequest
     */
    public static function make(
        int $timeout = 30,
        int $connectTimeout = 10,
        int $retry = 2,
        int $retryDelay = 100
    ): PendingRequest {
        // Resolve Origin: prefer the actual request host; fall back to app.url (e.g. in CLI / queue context).
        try {
            $origin = request()->getSchemeAndHttpHost();
        } catch (\Throwable $e) {
            $origin = null;
        }
        if (empty($origin)) {
            $origin = rtrim((string) config('app.url', ''), '/');
        }

        $client = Http::timeout($timeout)
            ->connectTimeout($connectTimeout)
            ->retry($retry, $retryDelay)
            ->withHeaders(array_filter(['Origin' => $origin]));

        $apiKey = config('exment.ai_server_api_key');
        if (is_string($apiKey) && trim($apiKey) !== '') {
            $client = $client->withToken(trim($apiKey));
        }

        return $client;
    }

    /**
     * Warn if the marketplace API key is not configured.
     *
     * Always logs a warning to the application log.
     * In a web context (not CLI) also shows an admin toast notification.
     *
     * Call this once at the top of each public controller action so admins
     * are informed about the misconfiguration immediately.
     */
    public static function warnApiKey(): void
    {
        $apiKey = config('exment.ai_server_api_key');
        if (is_string($apiKey) && trim($apiKey) !== '') {
            return;
        }

        Log::warning('[PluginMarket] exment.ai_server_api_key is not configured. Marketplace requests will be sent without Bearer authentication.');

        if (!app()->runningInConsole()) {
            admin_toastr(exmtrans('plugin.market.message.api_key_not_configured'), 'warning');
        }
    }
}
