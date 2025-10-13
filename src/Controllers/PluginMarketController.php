<?php

namespace Exceedone\Exment\Controllers;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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

    public function checkoutPurchase(Request $request)
    {
        $tenantUuid = $this->getTenantUuid();
        if (empty($tenantUuid)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Missing tenant_uuid.',
            ], 400);
        }

        $pluginUuid = $request->input('plugin_uuid');
        if (!is_string($pluginUuid) || strlen(trim($pluginUuid)) === 0) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Missing plugin_uuid.',
            ], 422);
        }
        $pluginUuid = trim($pluginUuid);

        // Optional: if client sends tenant_uuid, ensure it matches server config.
        $clientTenantUuid = $request->input('tenant_uuid');
        if (is_string($clientTenantUuid) && strlen(trim($clientTenantUuid)) > 0) {
            if (trim($clientTenantUuid) !== $tenantUuid) {
                return response()->json([
                    'status' => 'failed',
                    'message' => 'Invalid tenant_uuid.',
                ], 422);
            }
        }

        $url = $this->getMarketplaceUrl() . '/api/plugins/checkout/purchase';

        Log::info('[PluginMarket] Checkout purchase requested', [
            'plugin_uuid' => $pluginUuid,
        ]);

        try {
            $response = Http::withoutVerifying()
                ->timeout(30)
                ->connectTimeout(10)
                ->retry(1, 200)
                ->acceptJson()
                ->asJson()
                ->post($url, [
                    'tenant_uuid' => $tenantUuid,
                    'plugin_uuid' => $pluginUuid,
                ]);

            $json = null;
            try {
                $json = $response->json();
            } catch (\Throwable $e) {
                $json = null;
            }

            if ($response->ok()) {
                if (is_array($json)) {
                    $status = $json['status'] ?? null;
                    if (is_string($status)) {
                        $statusLower = strtolower(trim($status));
                        if (in_array($statusLower, ['success', 'paid', 'completed', 'ok'], true)) {
                            $json['status'] = 'succeeded';
                        }
                    }

                    // Some APIs return boolean flags instead of a string status.
                    if (($json['success'] ?? null) === true) {
                        $json['status'] = 'succeeded';
                    }
                }

                return response()->json(is_array($json) ? $json : [], 200);
            }

            Log::warning('[PluginMarket] Checkout purchase request failed', [
                'status' => $response->status(),
                'url' => $url,
                'plugin_uuid' => $pluginUuid,
                // Do not log full response body (may contain sensitive data / be large).
                'message' => is_array($json) ? ($json['message'] ?? null) : null,
            ]);

            if (is_array($json)) {
                return response()->json($json, $response->status());
            }

            return response()->json([
                'status' => 'failed',
                'message' => 'Marketplace request failed.',
            ], $response->status());
        } catch (\Throwable $e) {
            Log::error('[PluginMarket] Checkout purchase exception: ' . $e->getMessage(), [
                'url' => $url,
                'plugin_uuid' => $pluginUuid,
            ]);
            return response()->json([
                'status' => 'failed',
                'message' => 'Checkout request failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    protected function appendTenantUuidToUrl(string $url, ?string $tenantUuid): string
    {
        // Do not mutate local file URLs.
        if (Str::startsWith($url, 'file://')) {
            return $url;
        }

        // Signed URLs: by default we do NOT mutate them (adding query params after signing invalidates the signature).
        // If you need tenant_uuid to be present in the download request URL anyway (for custom verifiers),
        // enable EXMENT_MARKET_FORCE_APPEND_TENANT_UUID_TO_SIGNED_URL=true.
        $looksSigned = str_contains($url, 'signature=')
            || str_contains($url, 'expires=')
            || str_contains($url, 'X-Amz-Signature=')
            || str_contains($url, 'X-Amz-Credential=');
        $forceAppendOnSigned = (bool) config('exment.market_force_append_tenant_uuid_to_signed_url', false);

        if (empty($tenantUuid)) {
            return $url;
        }

        // Already has tenant_uuid
        if (str_contains($url, 'tenant_uuid=')) {
            return $url;
        }

        if ($looksSigned && $forceAppendOnSigned) {
            // Try to insert tenant_uuid before signature for maximum compatibility with non-standard verifiers.
            $signatureKeys = ['signature=', 'X-Amz-Signature='];
            $signaturePos = null;
            foreach ($signatureKeys as $key) {
                $pos = strpos($url, $key);
                if ($pos !== false && ($signaturePos === null || $pos < $signaturePos)) {
                    $signaturePos = $pos;
                }
            }

            if ($signaturePos !== null) {
                $insert = 'tenant_uuid=' . urlencode((string) $tenantUuid) . '&';
                return substr($url, 0, $signaturePos) . $insert . substr($url, $signaturePos);
            }
            // Fallback: append at end.
        } elseif ($looksSigned) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';
        return $url . $separator . 'tenant_uuid=' . urlencode($tenantUuid);
    }

    protected function resignMarketplaceSignedUrl(string $url, ?string $tenantUuid, int $minutes = 10): string
    {
        if (empty($tenantUuid)) {
            return $url;
        }

        $key = config('exment.marketplace_app_key');
        if (!is_string($key) || trim($key) === '') {
            return $url;
        }
        // IMPORTANT: Laravel UrlGenerator signs using the raw app.key string (including "base64:" prefix if present).
        $key = trim($key);

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['path'])) {
            return $url;
        }

        $path = $parts['path'];

        $absoluteBase = '';
        if (!empty($parts['scheme']) && !empty($parts['host'])) {
            $absoluteBase = $parts['scheme'] . '://' . $parts['host'];
            if (!empty($parts['port'])) {
                $absoluteBase .= ':' . $parts['port'];
            }
            $absoluteBase .= $path;
        }

        // Build a query string that matches what Laravel will verify against.
        // Verification uses the raw QUERY_STRING order (minus signature), so we must sign the same order we output.
        $existingPairs = [];
        if (!empty($parts['query'])) {
            $existingPairs = array_values(array_filter(explode('&', (string) $parts['query']), function ($p) {
                return is_string($p) && $p !== '';
            }));
        }

        $keptPairs = [];
        foreach ($existingPairs as $pair) {
            $name = Str::before($pair, '=');
            if (in_array($name, ['signature', 'expires', 'tenant_uuid'], true)) {
                continue;
            }
            $keptPairs[] = $pair;
        }

        $expires = now()->addMinutes($minutes)->getTimestamp();
        $unsignedPairs = [
            'expires=' . $expires,
            'tenant_uuid=' . rawurlencode((string) $tenantUuid),
        ];
        $unsignedPairs = array_merge($unsignedPairs, $keptPairs);
        $unsignedQuery = implode('&', $unsignedPairs);

        $relative = (bool) config('exment.marketplace_resign_relative', false);
        $signingUrl = $relative ? $path : ($absoluteBase !== '' ? $absoluteBase : $path);

        $original = rtrim($signingUrl . '?' . $unsignedQuery, '?');
        $signature = hash_hmac('sha256', $original, $key);

        $finalBase = $absoluteBase !== '' ? $absoluteBase : $path;
        return $finalBase . '?' . $unsignedQuery . '&signature=' . $signature;
    }

    protected function getTenantUuid(): ?string
    {
        $tenantUuid = config('exment.market_tenant_uuid');
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
                admin_toastr(exmtrans('plugin.market.plugin_not_found'), 'error');
                $plugins = [];
                return $content->title(exmtrans('plugin.market.title'))
                    ->description(exmtrans('plugin.market.description'))
                    ->body(view('exment::plugin.market.index', compact('plugins', 'tenantUuid')));
            }

            $plugins = [];
            if ($response->ok()) {
                $plugins = $response->json();

                if (!is_array($plugins)) {
                    Log::warning("[PluginMarket] API returned invalid data", [
                        'status' => $response->status(),
                    ]);
                    $plugins = [];
                }
            } else {
                Log::warning("[PluginMarket] API request failed: {$marketplaceApi}", [
                    'status' => $response->status(),
                ]);
                // Don't abort to an error page; show toast and render empty list.
                admin_toastr(exmtrans('plugin.market.message.connection_error'), 'error');
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
                    $author = $plugin['author'] ?? ($plugin['user']['name'] ?? '');
                    return str_contains(strtolower($plugin['plugin_name'] ?? ''), $searchText)
                        || str_contains(strtolower($plugin['description'] ?? ''), $searchText)
                        || str_contains(strtolower($author), $searchText);
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

                // Paginate the (potentially large) marketplace list for better UX, especially on mobile.
                $perPage = (int) $request->input('per_page', 20);
                if ($perPage <= 0) {
                    $perPage = 20;
                }
                $perPage = min($perPage, 200);

                $page = (int) $request->input('page', 1);
                if ($page <= 0) {
                    $page = 1;
                }

                $total = count($plugins);
                $items = array_slice($plugins, ($page - 1) * $perPage, $perPage);
                $plugins = new LengthAwarePaginator($items, $total, $perPage, $page, [
                    'path' => $request->url(),
                    'pageName' => 'page',
                ]);
                $plugins->appends($request->except('page'));

            // Render interface
            return $content->title(exmtrans('plugin.market.title'))
                ->description(exmtrans('plugin.market.description'))
                ->body(view('exment::plugin.market.index', compact('plugins', 'tenantUuid')));

        } catch (\Throwable $e) {
            Log::error('[PluginMarket] Exception: ' . $e->getMessage());
            // Avoid rendering a full error page; keep the UI and show a toast.
            admin_toastr(exmtrans('plugin.market.message.connection_error'), 'error');
            $plugins = [];
            $tenantUuid = $this->getTenantUuid();
            return $content->title(exmtrans('plugin.market.title'))
                ->description(exmtrans('plugin.market.description'))
                ->body(view('exment::plugin.market.index', compact('plugins', 'tenantUuid')));
        }
    }


    /**
     * Override show method to set proper title/description
     */
    public function show($id, Content $content)
    {
        $detail = $this->detail($id);
        if ($detail instanceof \Illuminate\Http\RedirectResponse) {
            return $detail;
        }

        return $content->title(exmtrans('plugin.market.detail.title'))
            ->description(exmtrans('plugin.market.description'))
            ->body($detail);
    }

    public function detail($id)
    {
        try {
            $request = request();
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
                admin_toastr(exmtrans('plugin.market.plugin_not_found'), 'error');
                return redirect(admin_url('plugin-market'));
            }

            $plugin = $response->json();

            return view('exment::plugin.market.detail', compact('plugin'));
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error("[PluginMarket] Connection error: " . $e->getMessage());
            admin_toastr(exmtrans('plugin.market.message.connection_error'), 'error');
            return redirect(admin_url('plugin-market'));
        } catch (\Throwable $e) {
            Log::error('[PluginMarket] Detail exception: ' . $e->getMessage());
            admin_toastr(exmtrans('plugin.market.message.connection_error'), 'error');
            return redirect(admin_url('plugin-market'));
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
                'tenant_uuid' => $tenantUuid,
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

            if ((bool) config('exment.market_resign_signed_download_url', false)) {
                $downloadUrl = $this->resignMarketplaceSignedUrl($downloadUrl, $tenantUuid, 10);
            } else {
                $downloadUrl = $this->appendTenantUuidToUrl($downloadUrl, $tenantUuid);
            }

            Log::info("[PluginMarket] Downloading plugin", [
                'download_url' => $downloadUrl,
            ]);

            // Download plugin file
            try {
                $zipResp = Http::withoutVerifying()
                    ->timeout(60)
                    ->connectTimeout(10)
                    ->retry(1, 200)
                    ->get($downloadUrl);
            } catch (\Throwable $downloadError) {
                Log::warning('[PluginMarket] Download exception', [
                    'plugin_id' => $id,
                    'version_id' => $versionId,
                    'message' => $downloadError->getMessage(),
                ]);

                return response()->json(['error' => exmtrans('plugin.market.message.download_failed')], 500);
            }
            if ($zipResp->failed()) {
                $contentType = $zipResp->header('Content-Type');
                $bodyPreview = null;
                try {
                    if (is_string($contentType) && str_contains(strtolower($contentType), 'application/json')) {
                        $bodyPreview = $zipResp->json();
                    } else {
                        $body = $zipResp->body();
                        if (!is_string($body)) {
                            $body = '';
                        }
                        $bodyPreview = substr($body, 0, 2000);
                        $bodyPreview = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '.', $bodyPreview);
                        $bodyPreview = Str::limit($bodyPreview, 2000);
                    }
                } catch (\Throwable $previewError) {
                    $bodyPreview = '<<unable to read response body: ' . $previewError->getMessage() . '>>';
                }
                Log::warning('[PluginMarket] Download failed', [
                    'plugin_id' => $id,
                    'version_id' => $versionId,
                    'status' => $zipResp->status(),
                    'content_type' => $contentType,
                    'body_preview' => $bodyPreview,
                ]);
                return response()->json(['error' => exmtrans('plugin.market.message.download_failed')], 500);
            }
            $zipBytes = $zipResp->body();

            // Save to temporary location
            $tmpPath = 'tmp/' . Str::random(10) . '.zip';
            Storage::disk('local')->put($tmpPath, $zipBytes);

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
                    'plugin_id' => $id,
                    'version_id' => $versionId,
                ]);
                
                return response()->json([
                    'error' => exmtrans('plugin.market.message.install_failed') . ': ' . $installError->getMessage()
                ], 500);
            }

        } catch (\Throwable $e) {
            Log::error("[PluginMarket] Error installing plugin $id: " . $e->getMessage());
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
            Log::error("[PluginMarket] Error uninstalling plugin $id: " . $e->getMessage());
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
