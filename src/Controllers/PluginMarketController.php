<?php

namespace Exceedone\Exment\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Encore\Admin\Controllers\AdminController;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Services\Plugin\PluginInstaller;
use App\Http\Controllers\Controller;
use Encore\Admin\Layout\Content;
use Encore\Admin\Grid;
use Encore\Admin\Show;
use Encore\Admin\Form;
use Encore\Admin\Grid\ArrayDataSource;

class PluginMarketController extends AdminController
{
    protected $title = 'Plugin Market';

    protected function appendTenantUuidToUrl(string $url, ?string $tenantUuid): string
    {
        if (empty($tenantUuid)) {
            return $url;
        }

        // Already has tenant_uuid
        if (str_contains($url, 'tenant_uuid=')) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';
        return $url . $separator . 'tenant_uuid=' . urlencode($tenantUuid);
    }

    protected function getTenantUuid(): ?string
    {
        $tenantUuid = env('EXMENT_MARKET_TENANT_UUID');
        if (is_string($tenantUuid) && strlen(trim($tenantUuid)) > 0) {
            return trim($tenantUuid);
        }

        return null;
    }

    protected function getMarketplaceUrl()
    {
        static $url = null;
        if ($url === null) {
            // Use configured marketplace URL from exment config (no .env dependency)
            $url = rtrim(config('exment.market_plugin_url', 'https://exment.org'), '/');
        }
        return $url;
    }

    protected function getRepoUrl()
    {
        return $this->getMarketplaceUrl() . '/api/plugins';
    }

    protected function grid()
    {
        $tenantUuid = $this->getTenantUuid();
        $queryParams = [];
        if (!empty($tenantUuid)) {
            $queryParams['tenant_uuid'] = $tenantUuid;
        }

        // Call API repo  plugin list
        $response = Http::withoutVerifying()
            ->timeout(30)
            ->connectTimeout(10)
            ->retry(2, 100)
            ->get($this->getRepoUrl(), $queryParams);
        $data = $response->json() ?? [];

        // Grid with data from API
        $grid = new Grid(new ArrayDataSource(collect($data)));

        $grid->column('id', 'ID');
        $grid->column('name', 'Plugin Name');
        $grid->column('version', 'Version');
        $grid->column('description', 'Description');

        return $grid;
    }
    
    public function index(Content $content)
    {
        try {
            // Get search parameters from request
            $request = request();
            $keyword = $request->input('keyword');
            $type = $request->input('type');
            $status = $request->input('status');

            $tenantUuid = $this->getTenantUuid();

            // URL API Marketplace with search parameters
            $marketplaceApi = $this->getRepoUrl();
            $queryParams = [];

            if (!empty($tenantUuid)) {
                $queryParams['tenant_uuid'] = $tenantUuid;
            }
            
            if ($keyword) {
                $queryParams['keyword'] = $keyword;
                // New Market API uses `search`
                $queryParams['search'] = $keyword;
            }
            if ($type) {
                $queryParams['type'] = $type;
            }
            if ($status) {
                $queryParams['status'] = $status;
            }

            $response = Http::withoutVerifying()
                ->timeout(30)
                ->connectTimeout(10)
                ->retry(2, 100)
                ->get($marketplaceApi, $queryParams);

            // If tenant_uuid is provided but invalid, marketplace returns 404
            if (!empty($tenantUuid) && $response->status() === 404) {
                abort(404, exmtrans('plugin.market.plugin_not_found'));
            }

            $plugins = [];
            if ($response->ok()) {
                $plugins = $response->json();

                if (!is_array($plugins)) {
                    Log::warning("[PluginMarket] API returned invalid data", [
                        'response' => $response->body(),
                    ]);
                    $plugins = [];
                }
            } else {
                Log::warning("[PluginMarket] API request failed: {$marketplaceApi}", [
                    'status' => $response->status(),
                ]);
            }

            // Filter to show only free plugins and perform client-side filtering
            $plugins = collect($plugins);
            
            // OSS (no tenant_uuid): show free plugins only
            if (empty($tenantUuid)) {
                $plugins = $plugins->filter(function ($plugin) {
                    $isFree = $plugin['is_free'] ?? null;
                    if ($isFree !== null) {
                        return (bool)$isFree;
                    }
                    $price = floatval($plugin['price'] ?? 0);
                    return $price === 0.0;
                });
            }
            
            // Filter by keyword (search in plugin_name, description, author)
            if ($keyword) {
                $plugins = $plugins->filter(function ($plugin) use ($keyword) {
                    $searchText = strtolower($keyword);
                    return str_contains(strtolower($plugin['plugin_name'] ?? ''), $searchText)
                        || str_contains(strtolower($plugin['description'] ?? ''), $searchText)
                        || str_contains(strtolower($plugin['user']['name'] ?? ''), $searchText);
                });
            }
            
            // Filter by type
            if ($type) {
                $plugins = $plugins->filter(function ($plugin) use ($type) {
                    $pluginTypes = $plugin['plugin_types'] ?? '';
                    return str_contains(strtolower($pluginTypes), strtolower($type));
                });
            }
            
            // Filter by status
            if ($status) {
                $plugins = $plugins->filter(function ($plugin) use ($status) {
                    $checkStatus = strtolower($plugin['check_status'] ?? '');
                    return $checkStatus === strtolower($status);
                });
            }
            
            $plugins = $plugins->values()->all();

            // Enrich marketplace data with local install info so the view can
            // show install/update states. We match by plugin_name (case-insensitive).
            try {
                $installed = Plugin::all()->keyBy(function ($p) {
                    return strtolower($p->plugin_name ?? '');
                });

                foreach ($plugins as $i => $p) {
                    $nameKey = strtolower($p['plugin_name'] ?? '');

                    $isInstalled = $installed->has($nameKey) && !empty($nameKey);
                    $plugins[$i]['is_installed'] = $isInstalled;

                    if ($isInstalled) {
                        $installedVersion = $installed->get($nameKey)->version ?? null;
                        $plugins[$i]['current_version'] = $installedVersion;
                        // has_update = installed version < marketplace version
                        $plugins[$i]['has_update'] = $installedVersion && isset($p['version'])
                            ? version_compare($installedVersion, $p['version'], '<')
                            : false;
                    } else {
                        $plugins[$i]['current_version'] = null;
                        $plugins[$i]['has_update'] = false;
                    }
                }
            } catch (\Throwable $e) {
                // If anything goes wrong during enrichment, log and continue with raw data
                Log::warning('[PluginMarket] Failed to enrich plugin data with local install info: ' . $e->getMessage());
            }

            // Render interface
            return $content->title(exmtrans('plugin.market.title'))
                ->description(exmtrans('plugin.market.description'))
                ->body(view('exment::plugin.market.index', compact('plugins', 'tenantUuid')));

        } catch (\Throwable $e) {
            Log::error('[PluginMarket] Exception: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            abort(500, 'Plugin Market error: ' . $e->getMessage());
        }
    }


    /**
     * Override show method to set proper title/description
     */
    public function show($id, Content $content)
    {
        return $content->title(exmtrans('plugin.market.detail.title'))
            ->description(exmtrans('plugin.market.description'))
            ->body($this->detail($id));
    }

    public function detail($id)
    {
        try {
            $tenantUuid = $this->getTenantUuid();
            $queryParams = [];
            if (!empty($tenantUuid)) {
                $queryParams['tenant_uuid'] = $tenantUuid;
            }

            $response = Http::withoutVerifying()
                ->timeout(30)
                ->connectTimeout(10)
                ->get("{$this->getRepoUrl()}/{$id}", $queryParams);

            if ($response->failed()) {
                abort(404, exmtrans('plugin.market.plugin_not_found'));
            }

            $plugin = $response->json();

            return view('exment::plugin.market.detail', compact('plugin'));
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error("[PluginMarket] Connection error: " . $e->getMessage());
            abort(500, exmtrans('plugin.market.message.connection_error'));
        }
    }


    protected function form()
    {
        $form = new Form(new Plugin());

        $form->text('name', __('Name plugin'));
        $form->text('version', __('Version'));
        $form->textarea('description', __('Description'));

        return $form;
    }
    /**
     * Install plugin from repository
     */
    public function install(Request $request, $id)
    {
        try {
            $versionId = $request->input('version'); // Selected version ID

            $tenantUuid = $this->getTenantUuid();
            $queryParams = [];
            if (!empty($tenantUuid)) {
                $queryParams['tenant_uuid'] = $tenantUuid;
            }

            Log::info("[PluginMarket] Install request", [
                'plugin_id' => $id,
                'version_id' => $versionId,
                'marketplace_url' => $this->getMarketplaceUrl(),
            ]);

            // Get plugin info from marketplace
            $pluginResponse = Http::withoutVerifying()
                ->timeout(30)
                ->connectTimeout(10)
                ->get("{$this->getRepoUrl()}/{$id}", $queryParams);
            
            if ($pluginResponse->failed()) {
                return response()->json(['error' => exmtrans('plugin.market.message.plugin_not_found')], 404);
            }

            $pluginData = $pluginResponse->json();
            $pluginName = $pluginData['plugin_name'] ?? null;

            // Check if plugin is already installed (for update case)
            $isUpdate = false;
            $installedPlugin = null;
            if ($pluginName) {
                $installedPlugin = Plugin::where('plugin_name', $pluginName)->first();
                if ($installedPlugin) {
                    $isUpdate = true;
                    Log::info("[PluginMarket] This is an update", [
                        'current_version' => $installedPlugin->version,
                    ]);
                }
            }

            // Validate version ID
            if (empty($versionId)) {
                return response()->json(['error' => exmtrans('plugin.market.message.please_select_version')], 400);
            }

            // Get version information
            $versionResponse = Http::withoutVerifying()
                ->timeout(30)
                ->connectTimeout(10)
                ->get("{$this->getRepoUrl()}/{$id}/versions", $queryParams);

            if ($versionResponse->failed()) {
                if (!empty($tenantUuid) && $versionResponse->status() === 404) {
                    return response()->json(['error' => exmtrans('plugin.market.message.plugin_not_found')], 404);
                }
                return response()->json(['error' => exmtrans('plugin.market.message.version_load_failed')], 400);
            }

            $versionsData = $versionResponse->json();
            $selectedVersion = collect($versionsData['versions'] ?? [])->firstWhere('id', (int)$versionId);

            if (!$selectedVersion) {
                return response()->json(['error' => exmtrans('plugin.market.message.version_not_found')], 404);
            }

            // Get download URL
            Log::info("[PluginMarket] Installing plugin version", [
                'version_id' => $versionId,
                'version' => $selectedVersion['version'] ?? 'UNKNOWN',
            ]);
            
            if (empty($selectedVersion['download_url'])) {
                return response()->json(['error' => exmtrans('plugin.market.message.no_download_url')], 400);
            }
            
            $downloadUrl = $selectedVersion['download_url'];
            $downloadUrl = $this->appendTenantUuidToUrl($downloadUrl, $tenantUuid);

            Log::info("[PluginMarket] Downloading plugin", [
                'download_url' => $downloadUrl,
            ]);

            // Download plugin file
            $zipResp = Http::withoutVerifying()->timeout(60)->get($downloadUrl);
            if ($zipResp->failed()) {
                return response()->json(['error' => exmtrans('plugin.market.message.download_failed')], 500);
            }

            // Save to temporary location
            $tmpPath = 'tmp/' . Str::random(10) . '.zip';
            Storage::disk('local')->put($tmpPath, $zipResp->body());

            $fullPath = Storage::disk('local')->path($tmpPath);

            // Install plugin using PluginInstaller
            try {
                // If this is an update, remove old version first
                if ($isUpdate && $installedPlugin) {
                    Log::info("[PluginMarket] Removing old version before update", [
                        'old_version' => $installedPlugin->version,
                    ]);
                    
                    // Delete plugin folder
                    $disk = Storage::disk(Define::DISKNAME_ADMIN);
                    $folder = $installedPlugin->getPath();
                    if ($disk->exists($folder)) {
                        $disk->deleteDirectory($folder);
                    }
                    
                    // Delete from database
                    $installedPlugin->delete();
                }
                
                // Install new version
                PluginInstaller::uploadPlugin(new \Illuminate\Http\File($fullPath));
                
                // Clean up temporary file
                Storage::disk('local')->delete($tmpPath);
                
                $message = $isUpdate ? exmtrans('plugin.market.message.update_success') : exmtrans('plugin.market.message.install_success', ['name' => $pluginName ?? '']);
                
                return response()->json([
                    'success' => true, 
                    'message' => $message
                ]);
            } catch (\Throwable $installError) {
                // Clean up temporary file
                Storage::disk('local')->delete($tmpPath);
                
                Log::error("[PluginMarket] Installation failed: " . $installError->getMessage(), [
                    'trace' => $installError->getTraceAsString(),
                ]);
                
                return response()->json([
                    'error' => exmtrans('plugin.market.message.install_failed') . ': ' . $installError->getMessage()
                ], 500);
            }

        } catch (\Throwable $e) {
            Log::error("[PluginMarket] Error installing plugin $id: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => exmtrans('plugin.market.message.install_error') . ': ' . $e->getMessage()], 500);
        }
    }

    /**
     * Uninstall plugin from system
     */
    public function uninstall(Request $request, $id)
    {
        try {
            $tenantUuid = $this->getTenantUuid();
            $queryParams = [];
            if (!empty($tenantUuid)) {
                $queryParams['tenant_uuid'] = $tenantUuid;
            }

            // Get plugin info from marketplace
            $pluginResponse = Http::withoutVerifying()
                ->timeout(30)
                ->connectTimeout(10)
                ->get("{$this->getRepoUrl()}/{$id}", $queryParams);
            
            if ($pluginResponse->failed()) {
                return response()->json(['error' => exmtrans('plugin.market.message.plugin_not_found')], 404);
            }

            $pluginData = $pluginResponse->json();
            $pluginName = $pluginData['plugin_name'] ?? null;

            if (!$pluginName) {
                return response()->json(['error' => exmtrans('plugin.market.message.invalid_plugin_data')], 400);
            }

            // Find installed plugin by plugin_name
            $installedPlugin = Plugin::where('plugin_name', $pluginName)->first();

            if (!$installedPlugin) {
                return response()->json(['error' => exmtrans('plugin.market.message.plugin_not_installed')], 404);
            }

            $pluginId = $installedPlugin->id;

            // Delete plugin folder using same logic as PluginController
            $disk = Storage::disk(Define::DISKNAME_ADMIN);
            $folder = $installedPlugin->getPath();
            if ($disk->exists($folder)) {
                $disk->deleteDirectory($folder);
            }

            // Delete plugin from database
            $installedPlugin->delete();

            return response()->json([
                 'result' => true,
                 'status' => true,
                 'swal' => exmtrans('plugin.market.message.uninstall_success', ['name' => $pluginName])
            ]);

        } catch (\Throwable $e) {
            Log::error("[PluginMarket] Error uninstalling plugin $id: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => exmtrans('plugin.market.message.uninstall_error') . ': ' . $e->getMessage()], 500);
        }
    }

    /**
     * Clear auto-install session data
     */
    public function clearAutoInstall(Request $request)
    {
        session()->forget('plugin_auto_install');
        return response()->json(['success' => true]);
    }
    
}
