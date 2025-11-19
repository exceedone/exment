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
            $resp = Http::get('http://marketplace.local/api/plugins');
            
            Log::info('[PluginRepository] API Response', [
                'url' => 'http://marketplace.local/api/plugins',
                'status' => $resp->status(),
                'body' => $resp->body()
            ]);
            
            if ($resp->successful()) {
                $plugins = $resp->json();
                
                // Transform data: map plugin data to include uuid and download_url
                return collect($plugins)->map(function ($plugin) {
                    // Get detailed info for each plugin to get download_url
                    $detailResp = Http::get('http://marketplace.local/api/plugins/' . $plugin['id']);
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
