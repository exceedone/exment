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
    protected $repoUrl = 'http://marketplace.local/api/plugins';


    protected $title = 'Plugin Market';

    protected function grid()
    {
        // Call API repo  plugin list
        $response = \Http::get($this->repoUrl);
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
            // URL API Marketplace, ví dụ: http://marketplace.local/api/plugins
            $marketplaceApi = $this->repoUrl;
            $response = Http::withoutVerifying()->get($marketplaceApi);

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
                Log::warning("[PluginMarket] API request failed: {$marketplaceApi}");
            }

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

            // Render giao diện
            return $content->title(__('exment::plugin.market.title'))
                ->description(__('exment::plugin.market.description'))
                ->body(view('exment::plugin.market.index', compact('plugins')));

        } catch (\Throwable $e) {
            Log::error('[PluginMarket] Exception: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            abort(500, 'Plugin Market error: ' . $e->getMessage());
        }
    }


    public function detail($id)
    {
        $response = Http::get("http://marketplace.local/api/plugins/{$id}");

        if ($response->failed()) {
            abort(404, 'Plugin not found in marketplace');
        }

        $plugin = $response->json();

        return view('exment::plugin.market.detail', compact('plugin'));
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
     * Cài đặt plugin từ repo
     */
    public function install(Request $request, $id)
    {
        try {
            $license = $request->input('license_key');

            // Get plugin info from marketplace
            $pluginResponse = Http::withoutVerifying()->get("{$this->repoUrl}/{$id}");
            
            if ($pluginResponse->failed()) {
                return response()->json(['error' => 'Plugin not found in marketplace'], 404);
            }

            $pluginData = $pluginResponse->json();
            $price = $pluginData['price'] ?? 0;

            // If plugin is paid, validate license
            if ($price > 0) {
                if (empty($license)) {
                    return response()->json(['error' => 'License key is required for paid plugins'], 400);
                }

                // Validate license with marketplace server
                $response = Http::timeout(30)->post("http://marketplace.local/api/plugin/validate-license", [
                    'plugin_id' => $id,
                    'license_key' => $license,
                    'user_id' => auth()->id(),
                ]);

                if ($response->failed()) {
                    return response()->json(['error' => 'License validation failed'], 400);
                }

                $data = $response->json();
                if (empty($data['download_url'])) {
                    return response()->json(['error' => 'No plugin download link available'], 400);
                }

                $downloadUrl = $data['download_url'];
            } else {
                // Free plugin: use download_url from plugin data
                if (empty($pluginData['download_url'])) {
                    return response()->json(['error' => 'No plugin download link available'], 400);
                }
                $downloadUrl = $pluginData['download_url'];
            }

            // Download plugin file
            $zipResp = Http::withoutVerifying()->timeout(60)->get($downloadUrl);
            if ($zipResp->failed()) {
                return response()->json(['error' => 'Failed to download plugin file'], 500);
            }

            // Save to temporary location
            $tmpPath = 'tmp/' . Str::random(10) . '.zip';
            Storage::disk('local')->put($tmpPath, $zipResp->body());

            $fullPath = Storage::disk('local')->path($tmpPath);

            // Install plugin using PluginInstaller
            try {
                PluginInstaller::uploadPlugin(new \Illuminate\Http\File($fullPath));
                
                // Clean up temporary file
                Storage::disk('local')->delete($tmpPath);
                
                return response()->json([
                    'success' => true, 
                    'message' => 'Plugin installed successfully'
                ]);
            } catch (\Throwable $installError) {
                // Clean up temporary file
                Storage::disk('local')->delete($tmpPath);
                
                Log::error("[PluginMarket] Installation failed: " . $installError->getMessage(), [
                    'trace' => $installError->getTraceAsString(),
                ]);
                
                return response()->json([
                    'error' => 'Installation failed: ' . $installError->getMessage()
                ], 500);
            }

        } catch (\Throwable $e) {
            Log::error("[PluginMarket] Error installing plugin $id: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'An error occurred while installing the plugin: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Uninstall plugin from system
     */
    public function uninstall(Request $request, $id)
    {
        try {
            // Get plugin info from marketplace
            $pluginResponse = Http::withoutVerifying()->get("{$this->repoUrl}/{$id}");
            
            if ($pluginResponse->failed()) {
                return response()->json(['error' => 'Plugin not found in marketplace'], 404);
            }

            $pluginData = $pluginResponse->json();
            $pluginName = $pluginData['plugin_name'] ?? null;

            if (!$pluginName) {
                return response()->json(['error' => 'Invalid plugin data'], 400);
            }

            // Find installed plugin by plugin_name
            $installedPlugin = Plugin::where('plugin_name', $pluginName)->first();

            if (!$installedPlugin) {
                return response()->json(['error' => 'Plugin is not installed'], 404);
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
                'success' => true, 
                'message' => 'Plugin uninstalled successfully'
            ]);

        } catch (\Throwable $e) {
            Log::error("[PluginMarket] Error uninstalling plugin $id: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'An error occurred while uninstalling the plugin: ' . $e->getMessage()], 500);
        }
    }
    
}
