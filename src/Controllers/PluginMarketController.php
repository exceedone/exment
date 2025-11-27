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

    protected function getMarketplaceUrl()
    {
        static $url = null;
        if ($url === null) {
            $url = rtrim(env('MARKETPLACE_URL', 'http://marketplace.local'), '/');
        }
        return $url;
    }

    protected function getRepoUrl()
    {
        return $this->getMarketplaceUrl() . '/api/plugins';
    }

    protected function grid()
    {
        // Call API repo  plugin list
        $response = Http::withoutVerifying()
            ->timeout(30)
            ->connectTimeout(10)
            ->retry(2, 100)
            ->get($this->getRepoUrl());
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
            $price = $request->input('price');
            $status = $request->input('status');

            // URL API Marketplace with search parameters
            $marketplaceApi = $this->getRepoUrl();
            $queryParams = [];
            
            if ($keyword) {
                $queryParams['keyword'] = $keyword;
            }
            if ($type) {
                $queryParams['type'] = $type;
            }
            if ($price) {
                $queryParams['price'] = $price;
            }
            if ($status) {
                $queryParams['status'] = $status;
            }

            $response = Http::withoutVerifying()
                ->timeout(30)
                ->connectTimeout(10)
                ->retry(2, 100)
                ->get($marketplaceApi, $queryParams);

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

            // If API doesn't support search, perform client-side filtering
            if (!empty($queryParams) && $response->ok()) {
                $plugins = collect($plugins);
                
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
                
                // Filter by price
                if ($price === 'free') {
                    $plugins = $plugins->filter(function ($plugin) {
                        return empty($plugin['price']) || $plugin['price'] == 0;
                    });
                } elseif ($price === 'paid') {
                    $plugins = $plugins->filter(function ($plugin) {
                        return !empty($plugin['price']) && $plugin['price'] > 0;
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
            return $content->title(exmtrans('plugin.market.title'))
                ->description(exmtrans('plugin.market.description'))
                ->body(view('exment::plugin.market.index', compact('plugins')));

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
            $response = Http::withoutVerifying()
                ->timeout(30)
                ->connectTimeout(10)
                ->get("{$this->getRepoUrl()}/{$id}");

            if ($response->failed()) {
                abort(404, exmtrans('plugin.market.plugin_not_found'));
            }

            $plugin = $response->json();

            return view('exment::plugin.market.detail', compact('plugin'));
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error("[PluginMarket] Connection error: " . $e->getMessage());
            abort(500, 'Cannot connect to marketplace server. Please check MARKETPLACE_URL in .env');
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
     * Cài đặt plugin từ repo
     */
    public function install(Request $request, $id)
    {
        try {
            $license = $request->input('license_key');
            $versionId = $request->input('version'); // Version ID được chọn

            Log::info("[PluginMarket] Install request", [
                'plugin_id' => $id,
                'version_id' => $versionId,
                'has_license' => !empty($license),
                'marketplace_url' => $this->getMarketplaceUrl(),
            ]);

            // Get plugin info from marketplace
            $pluginResponse = Http::withoutVerifying()
                ->timeout(30)
                ->connectTimeout(10)
                ->get("{$this->getRepoUrl()}/{$id}");
            
            if ($pluginResponse->failed()) {
                return response()->json(['error' => 'Plugin not found in marketplace'], 404);
            }

            $pluginData = $pluginResponse->json();
            $price = $pluginData['price'] ?? 0;
            $isUpdate = false;
            $installedPlugin = null;

            // Validate version ID
            if (empty($versionId)) {
                return response()->json(['error' => 'Please select a version to install'], 400);
            }

            // Get download URL based on plugin type (paid/free)
            if ($price > 0) {
                // Paid plugin: check if license key is provided
                if (empty($license)) {
                    // Redirect to payment page
                    $callbackUrl = admin_url("plugin-market/{$id}/payment-callback");
                    
                    // Check if using mock payment (for testing)
                    // Set PLUGIN_MARKET_USE_MOCK=true in .env for testing
                    // Set PLUGIN_MARKET_USE_MOCK=false in .env for production
                    $useMockPayment = env('PLUGIN_MARKET_USE_MOCK', true);
                    
                    if ($useMockPayment) {
                        // Testing: Use mock payment page
                        $paymentUrl = url("/mock-payment-page.php") . "?" . http_build_query([
                            'plugin_id' => $id,
                            'version_id' => $versionId,
                            'callback_url' => $callbackUrl,
                            'user_id' => auth()->id(),
                            'user_email' => auth()->user()->email ?? '',
                        ]);
                    } else {
                        // Production: Use marketplace payment page
                        $paymentUrl = "{$this->getMarketplaceUrl()}/payment/plugin/{$id}?" . http_build_query([
                            'version_id' => $versionId,
                            'callback_url' => $callbackUrl,
                            'user_id' => auth()->id(),
                            'user_email' => auth()->user()->email ?? '',
                        ]);
                    }
                    
                    return response()->json([
                        'redirect' => $paymentUrl
                    ]);
                }

                // Check if this is a mock license key (for testing)
                $isMockLicense = strpos($license, 'MOCK-LICENSE-') === 0;
                
                if ($isMockLicense) {
                    // For mock license, skip validation and get download URL directly
                    Log::info("[PluginMarket] Using mock license key, skipping validation", [
                        'license_key' => $license,
                    ]);
                    
                    // Get download URL from versions endpoint
                    $versionResponse = Http::withoutVerifying()
                        ->timeout(30)
                        ->connectTimeout(10)
                        ->get("{$this->getRepoUrl()}/{$id}/versions");
                    
                    if ($versionResponse->failed()) {
                        return response()->json(['error' => 'Failed to fetch versions'], 400);
                    }
                    
                    $versionsData = $versionResponse->json();
                    $selectedVersion = collect($versionsData['versions'] ?? [])->firstWhere('id', (int)$versionId);
                    
                    if (!$selectedVersion || empty($selectedVersion['download_url'])) {
                        return response()->json(['error' => 'No download URL available for this version'], 400);
                    }
                    
                    $downloadUrl = $selectedVersion['download_url'];
                } else {
                    // Real license: validate with marketplace server
                    $licenseResponse = Http::withoutVerifying()
                        ->timeout(30)
                        ->post("{$this->getMarketplaceUrl()}/api/plugin/validate-license", [
                            'plugin_id' => $id,
                            'license_key' => $license,
                            'version_id' => $versionId,
                            'user_id' => auth()->id(),
                        ]);

                    if ($licenseResponse->failed()) {
                        return response()->json(['error' => 'License validation failed'], 400);
                    }

                    $licenseData = $licenseResponse->json();
                    if (empty($licenseData['download_url'])) {
                        return response()->json(['error' => 'No plugin download link available'], 400);
                    }

                    $downloadUrl = $licenseData['download_url'];
                }
            } else {
                // Free plugin: get download URL from version endpoint
                $versionResponse = Http::withoutVerifying()
                    ->timeout(30)
                    ->connectTimeout(10)
                    ->get("{$this->getRepoUrl()}/{$id}/versions");
                
                if ($versionResponse->failed()) {
                    return response()->json(['error' => 'Failed to fetch versions'], 400);
                }
                
                $versionsData = $versionResponse->json();
                $selectedVersion = collect($versionsData['versions'] ?? [])->firstWhere('id', (int)$versionId);
                
                Log::info("[PluginMarket] Selected version details", [
                    'version_id' => $versionId,
                    'found_version' => $selectedVersion ? $selectedVersion['version'] : 'NOT FOUND',
                    'download_url' => $selectedVersion['download_url'] ?? 'NO URL',
                ]);
                
                if (!$selectedVersion) {
                    return response()->json(['error' => 'Selected version not found'], 404);
                }
                
                if (empty($selectedVersion['download_url'])) {
                    return response()->json(['error' => 'No download URL available for this version'], 400);
                }
                
                $downloadUrl = $selectedVersion['download_url'];
            }

            Log::info("[PluginMarket] Downloading plugin", [
                'download_url' => $downloadUrl,
            ]);

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
                
                $message = $isUpdate ? 'Plugin updated successfully' : 'Plugin installed successfully';
                
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
            $pluginResponse = Http::withoutVerifying()
                ->timeout(30)
                ->connectTimeout(10)
                ->get("{$this->getRepoUrl()}/{$id}");
            
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
                 'result' => true,
                 'status' => true,
                 'swal' => exmtrans('plugin.market.message.uninstall_success', ['name' => $pluginName])
            ]);

        } catch (\Throwable $e) {
            Log::error("[PluginMarket] Error uninstalling plugin $id: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'An error occurred while uninstalling the plugin: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Handle payment callback from marketplace
     * Marketplace will redirect back here with license_key after successful payment
     */
    public function paymentCallback(Request $request, $id)
    {
        try {
            $licenseKey = $request->input('license_key');
            $versionId = $request->input('version_id');
            $status = $request->input('status'); // success, failed, cancelled
            
            Log::info("[PluginMarket] Payment callback received", [
                'plugin_id' => $id,
                'version_id' => $versionId,
                'status' => $status,
                'has_license' => !empty($licenseKey),
            ]);

            // Check payment status
            if ($status !== 'success') {
                $message = $status === 'cancelled' 
                    ? 'Payment cancelled by user' 
                    : 'Payment failed';
                
                return redirect(admin_url('plugin-market'))
                    ->with('errorMess', $message);
            }

            // Validate license key
            if (empty($licenseKey)) {
                return redirect(admin_url('plugin-market'))
                    ->with('errorMess', 'No license key received from payment gateway');
            }

            // Get plugin info
            $pluginResponse = Http::withoutVerifying()
                ->timeout(30)
                ->connectTimeout(10)
                ->get("{$this->getRepoUrl()}/{$id}");
            
            if ($pluginResponse->failed()) {
                return redirect(admin_url('plugin-market'))
                    ->with('errorMess', 'Plugin not found');
            }

            $pluginData = $pluginResponse->json();
            $pluginName = $pluginData['plugin_name'] ?? 'Unknown Plugin';

            // Store license key and version in session to auto-install
            session([
                'plugin_auto_install' => [
                    'plugin_id' => $id,
                    'version_id' => $versionId,
                    'license_key' => $licenseKey,
                    'plugin_name' => $pluginName,
                ]
            ]);

            // Redirect back to plugin market with success message
            // The frontend will detect the session and trigger auto-install
            return redirect(admin_url('plugin-market'))
                ->with('successMess', 'Payment successful! Installing plugin...');

        } catch (\Throwable $e) {
            Log::error("[PluginMarket] Error in payment callback for plugin $id: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            
            return redirect(admin_url('plugin-market'))
                ->with('errorMess', 'An error occurred: ' . $e->getMessage());
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
