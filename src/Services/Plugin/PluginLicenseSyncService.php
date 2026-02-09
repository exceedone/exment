<?php

namespace Exceedone\Exment\Services\Plugin;

use Carbon\Carbon;
use Exceedone\Exment\Model\Plugin;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PluginLicenseSyncService
{
    /** @var \Illuminate\Support\Collection<string, array>|null */
    protected $marketPluginsByName = null;

    protected function getTenantUuid(): ?string
    {
        $tenantUuid = config('exment.market_tenant_uuid');
        if (is_string($tenantUuid) && trim($tenantUuid) !== '') {
            return trim($tenantUuid);
        }

        return null;
    }

    protected function getMarketplaceBaseUrl(): string
    {
        return rtrim(config('exment.market_plugin_url', 'https://exment.org'), '/');
    }

    protected function getMarketplacePluginsApiUrl(): string
    {
        return $this->getMarketplaceBaseUrl() . '/api/plugins';
    }

    /**
     * Throttled sync.
     *
     * @param int $minutes Cache interval
     * @return void
     */
    public function syncThrottled(int $minutes = 1): void
    {
        $cacheKey = 'exment_plugin_license_sync_last_run';
        if (Cache::has($cacheKey)) {
            return;
        }

        // Set cache early to prevent stampede on concurrent requests.
        Cache::put($cacheKey, now(), now()->addMinutes(max(1, $minutes)));

        $this->sync();
    }

    /**
     * Main sync logic.
     */
    public function sync(): void
    {
        $marketByName = $this->fetchMarketplacePluginsByName();
        if ($marketByName === null) {
            return;
        }

        $changedCount = 0;
        foreach (Plugin::all() as $installedPlugin) {
            $nameKey = strtolower($installedPlugin->plugin_name ?? '');
            if ($nameKey === '' || !$marketByName->has($nameKey)) {
                continue;
            }

            $market = $marketByName->get($nameKey);

            if (!$this->isPaidPlugin($market)) {
                continue;
            }

            $hasLicense = (bool) ($market['has_license'] ?? false);
            $isExpiredOverGrace = $this->isExpiredOverGraceWeek($market);
            $isValid = $hasLicense && !$isExpiredOverGrace;

            $options = is_array($installedPlugin->options) ? $installedPlugin->options : [];
            $disabledByLicense = (bool) array_get($options, 'disabled_by_license', false);

            if (!$isValid) {
                if (boolval($installedPlugin->active_flg) || !$disabledByLicense) {
                    $installedPlugin->active_flg = 0;
                    $options['disabled_by_license'] = true;
                    $installedPlugin->options = $options;
                    $installedPlugin->save();
                    $changedCount++;
                }
                continue;
            }

            if ($disabledByLicense && !boolval($installedPlugin->active_flg)) {
                $installedPlugin->active_flg = 1;
                unset($options['disabled_by_license']);
                $installedPlugin->options = $options;
                $installedPlugin->save();
                $changedCount++;
            }
        }

        if ($changedCount > 0) {
            Plugin::clearCacheTrait();
            Log::info('[PluginLicenseSync] Synced plugin activation', ['changed' => $changedCount]);
        }
    }

    /**
     * Block manual activation when invalid.
     * Returns true if activation should be blocked.
     */
    public function shouldBlockActivation(string $pluginName): bool
    {
        $marketByName = $this->fetchMarketplacePluginsByName();
        if ($marketByName === null) {
            return false;
        }

        $nameKey = strtolower($pluginName);
        if ($nameKey === '' || !$marketByName->has($nameKey)) {
            return false;
        }

        $market = $marketByName->get($nameKey);
        if (!$this->isPaidPlugin($market)) {
            return false;
        }

        $hasLicense = (bool) ($market['has_license'] ?? false);
        $isExpiredOverGrace = $this->isExpiredOverGraceWeek($market);

        return !$hasLicense || $isExpiredOverGrace;
    }

    /**
     * Fetch marketplace plugins keyed by plugin_name.
     */
    public function fetchMarketplacePluginsByName(): ?Collection
    {
        if ($this->marketPluginsByName !== null) {
            return $this->marketPluginsByName;
        }

        $tenantUuid = $this->getTenantUuid();
        if ($tenantUuid === null) {
            // OSS: marketplace doesn't return paid items; no enforcement.
            $this->marketPluginsByName = null;
            return null;
        }

        try {
            $resp = Http::withoutVerifying()
                ->timeout(15)
                ->connectTimeout(5)
                ->retry(1, 100)
                ->get($this->getMarketplacePluginsApiUrl(), ['tenant_uuid' => $tenantUuid]);

            if (!$resp->ok()) {
                Log::info('[PluginLicenseSync] Marketplace license check skipped (API not ok)', [
                    'status' => $resp->status(),
                    'url' => $this->getMarketplacePluginsApiUrl(),
                ]);
                $this->marketPluginsByName = null;
                return null;
            }

            $marketPlugins = $resp->json();
            if (!is_array($marketPlugins)) {
                Log::warning('[PluginLicenseSync] Marketplace returned invalid data');
                $this->marketPluginsByName = null;
                return null;
            }

            $this->marketPluginsByName = collect($marketPlugins)->keyBy(function ($p) {
                return strtolower($p['plugin_name'] ?? '');
            });

            return $this->marketPluginsByName;
        } catch (\Throwable $e) {
            Log::warning('[PluginLicenseSync] Marketplace fetch failed: ' . $e->getMessage());
            $this->marketPluginsByName = null;
            return null;
        }
    }

    protected function isPaidPlugin(array $market): bool
    {
        $isFree = (bool) ($market['is_free'] ?? ((float) ($market['price'] ?? 0) <= 0));
        return !$isFree;
    }

    /**
     * Consider expired only when past expiry + 7 days.
     */
    protected function isExpiredOverGraceWeek(array $market): bool
    {
        $expiresAt = $this->getExpiresAt($market);
        if ($expiresAt instanceof Carbon) {
            return $expiresAt->copy()->addDays(7)->isPast();
        }

        // Fallback: if marketplace explicitly says expired but no parsable date exists, treat as expired.
        return (bool) ($market['is_expired'] ?? false);
    }

    protected function getExpiresAt(array $market): ?Carbon
    {
        $candidate = $market['expires_at']
            ?? $market['license_expires_at']
            ?? ($market['license_info']['expires_at'] ?? null)
            ?? ($market['license']['expires_at'] ?? null);

        if (is_string($candidate) && trim($candidate) !== '') {
            try {
                return Carbon::parse($candidate);
            } catch (\Throwable $e) {
                return null;
            }
        }

        if (is_int($candidate) || (is_string($candidate) && ctype_digit($candidate))) {
            try {
                return Carbon::createFromTimestamp((int) $candidate);
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }
}
