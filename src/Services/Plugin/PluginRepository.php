<?php
namespace Exceedone\Exment\Services\Plugin;

use Illuminate\Support\Facades\Http;

class PluginRepository
{
    public static function fetchVersions(): array
    {
        cache()->forget('plugin_repo_versions'); // chỉ để test
        return cache()->remember('plugin_repo_versions', 300, function () {
            $resp = Http::get(config('app.url') . '/api/mock/plugin-repo');
            if ($resp->successful()) {
                return $resp->json();
            }
            return [];
        });
    }

}
