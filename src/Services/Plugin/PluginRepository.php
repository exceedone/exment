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
            $marketplaceUrl = rtrim(env('MARKETPLACE_URL', 'http://marketplace.local'), '/');
            $apiUrl = $marketplaceUrl . '/api/plugins';
            
            $resp = Http::withoutVerifying()
                ->timeout(30)
                ->connectTimeout(10)
                ->retry(2, 100)
                ->get($apiUrl);
            
            Log::info('[PluginRepository] API Response', [
                'url' => $apiUrl,
                'marketplace_url' => $marketplaceUrl,
                'status' => $resp->status(),
                'body' => $resp->body()
            ]);
            
            if ($resp->successful()) {
                $plugins = $resp->json();
                
                Log::info('[PluginRepository] Raw plugins data', [
                    'count' => count($plugins),
                    'plugins' => $plugins
                ]);
                
                // Transform data: map plugin data to include uuid and download_url
                return collect($plugins)->map(function ($plugin) use ($marketplaceUrl) {
                    $pluginId = $plugin['id'] ?? $plugin['uuid'] ?? '';
                    
                    // Get latest version info from versions array
                    $latestVersion = collect($plugin['versions'] ?? [])->firstWhere('is_latest', true);
                    
                    // Build download URL from latest version
                    $downloadUrl = null;
                    if ($latestVersion && isset($latestVersion['id'])) {
                        $downloadUrl = $marketplaceUrl . '/api/plugins/' . $pluginId . '/versions/' . $latestVersion['id'] . '/download';
                    }
                    
                    Log::info('[PluginRepository] Mapping plugin', [
                        'plugin_id' => $pluginId,
                        'plugin_name' => $plugin['plugin_name'] ?? '',
                        'version' => $plugin['version'] ?? '',
                        'latest_version_id' => $latestVersion['id'] ?? null,
                        'download_url' => $downloadUrl
                    ]);
                    
                    $result = [
                        'uuid' => $plugin['uuid'] ?? $pluginId,
                        'plugin_name' => $plugin['plugin_name'] ?? '',
                        'latest_version' => $plugin['version'] ?? '',
                        'download_url' => $downloadUrl,
                        'marketplace_id' => $pluginId, // Add marketplace plugin ID for update
                    ];
                    
                    return $result;
                })->keyBy('uuid')->toArray();
            }
            return [];
        });
    }

}
