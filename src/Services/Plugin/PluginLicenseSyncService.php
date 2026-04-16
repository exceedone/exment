<?php

namespace Exceedone\Exment\Services\Plugin;

use Carbon\Carbon;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\NotifyService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class PluginLicenseSyncService
{
    /** @var \Illuminate\Support\Collection<string, array>|null */
    protected $marketPluginsByName = null;

    /** Prevents retrying the market HTTP call on the same instance after a failure. */
    private bool $fetchAttempted = false;

    protected function getThrottleCacheKey(): string
    {
        return 'exment_plugin_license_sync_last_run';
    }

    protected function getWarningSentCacheKey(string $pluginName): string
    {
        $date = now()->toDateString();
        return 'exment_plugin_license_warning_sent:' . sha1(strtolower($pluginName) . '|' . $date);
    }

    /**
     * @return string[]
     */
    protected function getWarningRecipients(): array
    {
        try {
            $adminIds = System::system_admin_users() ?? [];
            if (!is_array($adminIds) || empty($adminIds)) {
                return [];
            }

            $userTable = CustomTable::getEloquent(SystemTableName::USER);
            $users = collect($adminIds)
                ->map(function ($id) use ($userTable) {
                    return $userTable ? $userTable->getValueModel($id) : null;
                })
                ->filter();

            return array_values(array_filter(NotifyService::getAddresses($users->all())));
        } catch (\Throwable $e) {
            Log::warning('[PluginLicenseSync] Failed to resolve warning recipients: ' . $e->getMessage());
            return [];
        }
    }

    protected function sendExpiryWarningEmail(Plugin $installedPlugin, array $market, Carbon $expiresAt): void
    {
        $pluginName = (string) ($installedPlugin->plugin_name ?? '');
        if ($pluginName === '') {
            return;
        }

        $cacheKey = $this->getWarningSentCacheKey($pluginName);
        if (Cache::has($cacheKey)) {
            return;
        }

        $disableAt = $expiresAt->copy()->addDays(7);
        $daysLeft = now()->diffInDays($disableAt, false);
        if ($daysLeft < 0) {
            return;
        }

        $recipients = $this->getWarningRecipients();
        if (empty($recipients)) {
            return;
        }

        $pluginLabel = (string) ($installedPlugin->plugin_view_name ?? $installedPlugin->plugin_name ?? 'Plugin');
        $marketUrl = $this->getMarketplaceBaseUrl() . '/plugins';

        $subject = exmtrans('plugin.message.license_expired_warning_subject', [
            'plugin' => $pluginLabel,
        ]);

        $body = exmtrans('plugin.message.license_expired_warning_body', [
            'plugin' => $pluginLabel,
            'expires_at' => $expiresAt->toDateString(),
            'disable_at' => $disableAt->toDateString(),
            'days_left' => (string) $daysLeft,
            'market_url' => $marketUrl,
        ]);

        try {
            Mail::raw((string) $body, function ($message) use ($recipients, $subject) {
                $message->to($recipients)
                    ->subject((string) $subject);
            });

            // Mark as sent for today (until end of day) to avoid spamming on repeated syncs.
            Cache::put($cacheKey, now()->toISOString(), now()->endOfDay());
        } catch (TransportExceptionInterface $e) {
            Log::warning('[PluginLicenseSync] Warning email transport failed: ' . $e->getMessage());
        } catch (\Throwable $e) {
            Log::warning('[PluginLicenseSync] Warning email failed: ' . $e->getMessage());
        }
    }

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
    public function syncThrottled(int $minutes = 1440): void
    {
        // Cache::add is atomic: sets the key only if it does not exist, preventing
        // race conditions where two concurrent requests both pass a has() check.
        if (!Cache::add($this->getThrottleCacheKey(), now(), now()->addMinutes(max(1, $minutes)))) {
            return;
        }

        $this->sync();
    }

    /**
     * Force a sync now (ignore throttle), and then mark as throttled
     * so other pages won't re-sync for the given interval.
     */
    public function syncForced(int $minutesToThrottle = 1440): void
    {
        $cacheKey = $this->getThrottleCacheKey();
        // Mark early to prevent stampede on concurrent requests.
        Cache::put($cacheKey, now(), now()->addMinutes(max(1, $minutesToThrottle)));
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

        // Only enforce licenses on marketplace-installed plugins (local=false).
        // Chunk to avoid loading all rows into memory at once.
        Plugin::where('local', false)->chunk(100, function ($plugins) use ($marketByName, &$changedCount) {
            foreach ($plugins as $installedPlugin) {
                $nameKey = strtolower($installedPlugin->plugin_name ?? '');
                if ($nameKey === '' || !$marketByName->has($nameKey)) {
                    continue;
                }

                $market = $marketByName->get($nameKey);

                if (!$this->isPaidPlugin($market)) {
                    continue;
                }

                $isValid = $this->isLicenseValid($market);

                // Daily warning email while expired but still within the grace period.
                if ($isValid) {
                    $expiresAt = $this->getExpiresAt($market);
                    if ($expiresAt instanceof Carbon && $expiresAt->isPast()) {
                        $this->sendExpiryWarningEmail($installedPlugin, $market, $expiresAt);
                    }
                }

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
        });

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

        return !$this->isLicenseValid($market);
    }

    /**
     * Fetch marketplace plugins keyed by plugin_name.
     */
    public function fetchMarketplacePluginsByName(): ?Collection
    {
        // Return cached result on success, or null immediately on a previous failure —
        // avoids retrying the HTTP call multiple times within the same request lifecycle.
        if ($this->fetchAttempted) {
            return $this->marketPluginsByName;
        }
        $this->fetchAttempted = true;

        $tenantUuid = $this->getTenantUuid();
        if ($tenantUuid === null) {
            // OSS: marketplace doesn't return paid items; no enforcement.
            $this->marketPluginsByName = null;
            return null;
        }

        try {
            $resp = PluginMarketClient::make(timeout: 15, connectTimeout: 5, retry: 1, retryDelay: 100)
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
     * Returns true when the license is present and not expired beyond the grace period.
     */
    protected function isLicenseValid(array $market): bool
    {
        $hasLicense = (bool) ($market['has_license'] ?? false);
        return $hasLicense && !$this->isExpiredOverGraceWeek($market);
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
            ?? $market['expired_at']
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
