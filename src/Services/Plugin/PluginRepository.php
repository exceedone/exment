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
                
                // Transform data: map plugin data to include uuid and download_url
                return collect($plugins)->map(function ($plugin) use ($marketplaceUrl) {
                    // Get detailed info for each plugin to get download_url
                    $detailUrl = $marketplaceUrl . '/api/plugins/' . $plugin['id'];
                    $detailResp = Http::withoutVerifying()
                        ->timeout(30)
                        ->connectTimeout(10)
                        ->get($detailUrl);
                    $detail = $detailResp->successful() ? $detailResp->json() : [];
                    
                    return [
                        'uuid' => $plugin['uuid'] ?? $plugin['id'], // Use uuid if available, otherwise id
                        'plugin_name' => $plugin['plugin_name'] ?? '',
                        'latest_version' => $plugin['version'] ?? '',
                        'download_url' => $detail['download_url'] ?? null,
                    ];
                })->keyBy('uuid')->toArray();
            }
            return [];
        });
    }

}
