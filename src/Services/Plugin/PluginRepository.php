<?php
namespace Exceedone\Exment\Services\Plugin;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PluginRepository
{
    public static function fetchVersions(): array
    {
        cache()->forget('plugin_repo_versions'); 
        return cache()->remember('plugin_repo_versions', 300, function () {
            $marketplaceUrl = rtrim(config('exment.market_plugin_url', 'https://exment.org'), '/');
            $apiUrl = $marketplaceUrl . '/api/plugins';

            $tenantUuid = config('exment.market_tenant_uuid');
            $queryParams = [];
            if (is_string($tenantUuid) && strlen(trim($tenantUuid)) > 0) {
                $tenantUuid = trim($tenantUuid);
                $queryParams['tenant_uuid'] = $tenantUuid;
            } else {
                $tenantUuid = null;
            }
            
            $resp = Http::withoutVerifying()
                ->timeout(30)
                ->connectTimeout(10)
                ->retry(2, 100)
                ->get($apiUrl, $queryParams);

            if (!$resp->successful()) {
                Log::warning('[PluginRepository] API request failed', [
                    'url' => $apiUrl,
                    'status' => $resp->status(),
                ]);
                return [];
            }
            
            $plugins = $resp->json();

            // Transform data: map plugin data to include uuid and download_url
            return collect($plugins)->map(function ($plugin) use ($marketplaceUrl, $tenantUuid) {
                    $pluginId = $plugin['id'] ?? $plugin['uuid'] ?? '';
                    
                    // Get latest version info from versions array
                    $versions = collect($plugin['versions'] ?? []);
                    $latestVersion = $versions->firstWhere('is_latest', true);
                    if (!$latestVersion) {
                        // Fallback when API doesn't provide is_latest
                        $latestVersion = $versions->first();
                    }

                    $latestVersionName = $latestVersion['version']
                        ?? ($plugin['version'] ?? $plugin['latest_version'] ?? '');
                    
                    // Build download URL from latest version
                    $downloadUrl = null;
                    if ($latestVersion && isset($latestVersion['id'])) {
                        $downloadUrl = $marketplaceUrl . '/api/plugins/' . $pluginId . '/versions/' . $latestVersion['id'] . '/download';

                        // If tenant_uuid exists, ensure it is propagated to download endpoint
                        if (!empty($tenantUuid) && !str_contains($downloadUrl, 'tenant_uuid=')) {
                            $downloadUrl .= '?tenant_uuid=' . urlencode($tenantUuid);
                        }
                    }
                    
                    $result = [
                        'uuid' => $plugin['uuid'] ?? $pluginId,
                        'plugin_name' => $plugin['plugin_name'] ?? '',
                        'latest_version' => $latestVersionName,
                        'download_url' => $downloadUrl,
                        'marketplace_id' => $pluginId, // Add marketplace plugin ID for update
                    ];
                    
                    return $result;
                })->keyBy('uuid')->toArray();
        });
    }

}
