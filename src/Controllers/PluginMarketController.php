<?php

namespace Exceedone\Exment\Controllers;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Encore\Admin\Controllers\AdminController;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Services\Plugin\PluginInstaller;
use Exceedone\Exment\Services\Plugin\PluginMarketClient;
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
        PluginMarketClient::warnApiKey();
        $apiKey = $this->getApiKey();
        if (empty($apiKey)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Missing api_key.',
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

        $url = $this->getMarketplaceUrl() . '/api/plugins/checkout/purchase';

        Log::info('[PluginMarket] Checkout purchase requested', [
            'plugin_uuid' => $pluginUuid,
        ]);

        try {
            $response = PluginMarketClient::make(retry: 1, retryDelay: 200)
                ->acceptJson()
                ->asJson()
                ->post($url, [
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

    /**
     * Call the marketplace API and return the raw plugin array.
     * Returns an empty array on HTTP errors (toast already set).
     * Authentication is handled via Bearer token in PluginMarketClient.
     *
     * @param  string $marketplaceApi
     * @param  array  $queryParams
     * @return array|null
     */
    protected function fetchMarketplacePlugins(string $marketplaceApi, array $queryParams): ?array
    {
        $response = PluginMarketClient::make()
            ->get($marketplaceApi, $queryParams);


        if ($response->ok()) {
            $plugins = $response->json();

            if (!is_array($plugins)) {
                Log::warning('[PluginMarket] API returned invalid data', [
                    'status' => $response->status(),
                ]);
                return [];
            }

            return $plugins;
        }

        Log::warning("[PluginMarket] API request failed: {$marketplaceApi}", [
            'status' => $response->status(),
        ]);
        // Don't abort to an error page; show toast and render empty list.
        admin_toastr(exmtrans('plugin.market.message.connection_error'), 'error');

        return [];
    }

    /**
     * Apply client-side filters to the marketplace plugin collection.
     *
     * @param  \Illuminate\Support\Collection $plugins
     * @param  string|null $keyword
     * @param  string|null $type
     * @param  string|null $status
     * @return array
     */
    protected function filterPlugins(
        \Illuminate\Support\Collection $plugins,
        ?string $keyword,
        ?string $type,
        ?string $status,
        ?string $installStatus = null
    ): array {
        // All plugins (free and paid) are shown regardless of API key presence.
        // The license/owner badge and purchase button in the view handle paid-plugin UX.

        // Filter by keyword (search in plugin_name, plugin_view_name, description, author)
        if ($keyword) {
            $plugins = $plugins->filter(function ($plugin) use ($keyword) {
                $searchText = strtolower($keyword);
                $author = $plugin['author'] ?? ($plugin['user']['name'] ?? '');
                return str_contains(strtolower($plugin['plugin_name'] ?? ''), $searchText)
                    || str_contains(strtolower($plugin['plugin_view_name'] ?? ''), $searchText)
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

        // Filter by install status (local)
        if ($installStatus === 'installed') {
            $plugins = $plugins->filter(fn($p) => !empty($p['is_installed']));
        } elseif ($installStatus === 'not_installed') {
            $plugins = $plugins->filter(fn($p) => empty($p['is_installed']));
        }

        return $plugins->values()->all();
    }

    /**
     * Enrich marketplace plugin array with local install info.
     * Sets is_installed, current_version and has_update on each item.
     *
     * @param  array $plugins
     * @return array
     */
    protected function enrichWithLocalInfo(array $plugins): array
    {
        try {
            // Only consider plugins installed from the marketplace (local = false)
            $installed = Plugin::where('local', false)->get()->keyBy(function ($p) {
                return strtolower($p->plugin_name ?? '');
            });
        } catch (\Throwable $e) {
            Log::warning('[PluginMarket] Failed to load installed plugins: ' . $e->getMessage());
            return $plugins;
        }

        // Enrich + normalize each marketplace plugin inline (single pass).
        // Use associative array for O(1) lookup instead of in_array (O(n)).
        $marketplaceNames = [];
        foreach ($plugins as $i => $p) {
            $nameKey = strtolower($p['plugin_name'] ?? '');
            $marketplaceNames[$nameKey] = true;

            $isInstalled = !empty($nameKey) && $installed->has($nameKey);
            $p['is_installed'] = $isInstalled;

            if ($isInstalled) {
                $localPlugin      = $installed->get($nameKey);
                $installedVersion = $localPlugin->version ?? null;
                $marketVersion    = $p['version'] ?? null;

                $p['current_version'] = $installedVersion;
                $p['has_update']      = $installedVersion && $marketVersion
                    ? version_compare($installedVersion, $marketVersion, '<')
                    : false;
                $p['local_db_id']     = $localPlugin->id;

                $isDisabled = isset($localPlugin->active_flg) && !(bool) $localPlugin->active_flg;

                $availableVersionStrings = collect($p['versions'] ?? [])
                    ->pluck('version')
                    ->filter()
                    ->map(fn($v) => (string) $v)
                    ->all();

                if (!empty($availableVersionStrings) && $installedVersion !== null) {
                    $versionExists = in_array((string) $installedVersion, $availableVersionStrings, true);
                } else {
                    // Fallback: marketplace has no versions list in response — treat as existing
                    $versionExists = $marketVersion !== null;
                }

                $p['plugin_status'] = $this->resolvePluginStatus(
                    isInstalled: true,
                    isOnMarket: true,
                    isDisabled: $isDisabled,
                    versionExists: $versionExists
                );
            } else {
                $p['current_version'] = null;
                $p['has_update']      = false;
                $p['plugin_status']   = $this->resolvePluginStatus(
                    isInstalled: false,
                    isOnMarket: true,
                    isDisabled: false,
                    versionExists: true
                );
            }

            // Normalize inline — no separate third loop needed
            $plugins[$i] = $this->normalizePluginForDisplay($p);
        }

        foreach ($installed as $nameKey => $localPlugin) {
            if (empty($nameKey) || isset($marketplaceNames[$nameKey])) {
                continue;
            }

            try {
                // Use getAttributes() to get raw DB values and bypass accessors/casts
                // that may return arrays (e.g. plugin_types accessor, options/custom_options json cast)
                $raw = $localPlugin->getAttributes();

                // Sanitize any remaining non-scalar values for blade compatibility
                foreach ($raw as $k => $v) {
                    if (is_array($v)) {
                        $raw[$k] = implode(',', array_map('strval', $v));
                    } elseif (is_object($v)) {
                        $raw[$k] = (string) $v;
                    }
                }

                $isDisabled = isset($localPlugin->active_flg) && !(bool) $localPlugin->active_flg;

                // Normalize inline when appending
                $plugins[] = $this->normalizePluginForDisplay(array_merge($raw, [
                    'is_installed'    => true,
                    'current_version' => $localPlugin->version ?? null,
                    'has_update'      => false,
                    'plugin_status'   => $this->resolvePluginStatus(
                        isInstalled: true,
                        isOnMarket: false,
                        isDisabled: $isDisabled,
                        versionExists: false
                    ),
                ]));
            } catch (\Throwable $e) {
                Log::warning('[PluginMarket] Failed to append local plugin "' . $nameKey . '": ' . $e->getMessage());
            }
        }

        return $plugins;
    }

    protected function resolvePluginStatus(
        bool $isInstalled,
        bool $isOnMarket,
        bool $isDisabled,
        bool $versionExists
    ): string {
        if (!$isInstalled) {
            return 'not_installed';
        }

        if ($isDisabled) {
            return 'disabled';
        }

        if (!$isOnMarket) {
            return 'market_deleted';
        }

        if (!$versionExists) {
            return 'version_deleted';
        }

        return 'installed';
    }
    protected function normalizePluginForDisplay(array $p): array
    {
        $rawPrice  = $p['price'] ?? null;
        $isFree    = (bool) ($p['is_free'] ?? ((float) ($rawPrice ?? 0) <= 0));
        $hasLicense = $isFree ? true : (bool) ($p['has_license'] ?? false);
        $isExpired  = (bool) ($p['is_expired'] ?? false);

        // plugin_types: map numeric DB values to string names
        $pluginTypeMap = [
            '0' => 'trigger',
            '1' => 'page',
            '2' => 'api',
            '3'  => 'document',
            '4' => 'batch',
            '5' => 'dashboard',
            '6' => 'import',
            '7'  => 'script',
            '8' => 'style',
            '9' => 'validator',
            '10' => 'export',
            '11' => 'button',
            '12' => 'event',
            '13' => 'view',
            '14' => 'crud',
        ];
        $rawTypes           = (string) ($p['plugin_types'] ?? '');
        $pluginTypesDisplay = implode(', ', array_filter(array_map(function ($t) use ($pluginTypeMap) {
            $t = trim($t);
            return $t !== '' ? ($pluginTypeMap[$t] ?? $t) : null;
        }, explode(',', $rawTypes))));

        // price label
        $priceLabel     = '';
        $currencySuffix = null;
        if ($rawPrice !== null && $rawPrice !== '' && is_numeric($rawPrice)) {
            $currencyLabel      = $p['currency'] ?? null;
            $currencyNormalized = is_string($currencyLabel) ? strtoupper(trim($currencyLabel)) : null;
            $isYen              = empty($currencyNormalized) || in_array($currencyNormalized, ['JPY', 'YEN', '円', '¥'], true);
            $priceLabel         = $isYen
                ? number_format((float) $rawPrice, 0)
                : number_format((float) $rawPrice, 2);
            $currencySuffix     = $isYen ? '円' : ($currencyLabel ?? null);
        }

        // status badge CSS class
        $pluginStatus    = $p['plugin_status'] ?? 'not_installed';
        $statusBadgeClass = match ($pluginStatus) {
            'installed'       => 'bg-success',
            'disabled'        => 'bg-secondary',
            'market_deleted'  => 'bg-danger',
            'version_deleted' => 'bg-warning',
            default           => 'bg-light text-dark',
        };

        return array_merge($p, [
            'is_active'            => isset($p['check_status']) && strtolower($p['check_status']) === 'active',
            'is_free'              => $isFree,
            'has_license'          => $hasLicense,
            'is_expired'           => $isExpired,
            'can_install'          => $isFree || ($hasLicense && !$isExpired),
            'should_show_payment'  => (!$isFree) && ((!$hasLicense) || $isExpired),
            'display_uuid'         => $p['uuid'] ?? ($p['plugin_uuid'] ?? null),
            // route_key: local-only plugins (market_deleted) use "local-{db_id}" so
            // detail() can detect them and load from DB instead of calling marketplace API.
            'route_key'            => ($p['plugin_status'] ?? '') === 'market_deleted'
                ? 'local-' . ($p['id'] ?? '')
                : ($p['id'] ?? ''),
            'is_market_plugin'     => ($p['plugin_status'] ?? '') !== 'market_deleted',
            'local_db_id'          => $p['local_db_id']
                ?? (($p['plugin_status'] ?? '') === 'market_deleted' ? ($p['id'] ?? null) : null),
            'plugin_types_display' => $pluginTypesDisplay ?: '—',
            'price_label'          => $priceLabel,
            'currency_suffix'      => $currencySuffix,
            'status_badge_class'   => $statusBadgeClass,
        ]);
    }

    protected function getApiKey(): ?string
    {
        $key = config('exment.ai_server_api_key');
        if (is_string($key) && strlen(trim($key)) > 0) {
            return trim($key);
        }

        return null;
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
        // Call API repo  plugin list (Bearer auth via PluginMarketClient)
        $response = PluginMarketClient::make()
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
        PluginMarketClient::warnApiKey();
        try {
            // Get search parameters from request
            $request = request();
            $keyword = $request->input('keyword');
            $type = $request->input('type');
            $status = $request->input('status');
            $installStatus = $request->input('install_status');

            $apiKey = $this->getApiKey();

            // URL API Marketplace with search parameters (Bearer auth via PluginMarketClient)
            $marketplaceApi = $this->getRepoUrl();
            $queryParams = [];

            if ($type) {
                $queryParams['type'] = $type;
            }

            $plugins = $this->fetchMarketplacePlugins($marketplaceApi, $queryParams);

            // Enrich with local install info FIRST (adds market_deleted plugins),
            // then filter so keyword/type search applies uniformly to all plugins including local-only ones.
            $plugins = $this->enrichWithLocalInfo($plugins);

            $plugins = collect($plugins);

            $plugins = $this->filterPlugins($plugins, $keyword, $type, $status, $installStatus);

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
                ->body(view('exment::plugin.market.index', compact('plugins', 'apiKey')));
        } catch (\Throwable $e) {
            Log::error('[PluginMarket] Exception: ' . $e->getMessage());
            // Avoid rendering a full error page; keep the UI and show a toast.
            admin_toastr(exmtrans('plugin.market.message.connection_error'), 'error');
            $plugins = [];
            $apiKey = $this->getApiKey();
            return $content->title(exmtrans('plugin.market.title'))
                ->description(exmtrans('plugin.market.description'))
                ->body(view('exment::plugin.market.index', compact('plugins', 'apiKey')));
        }
    }


    /**
     * Proxy: fetch plugin versions from marketplace server-side (keeps api_key off the browser).
     */
    public function pluginVersions(Request $request, $id)
    {
        try {
            $response = PluginMarketClient::make(timeout: 15)
                ->get("{$this->getRepoUrl()}/{$id}/versions");

            if ($response->failed()) {
                return response()->json(['error' => 'Failed to fetch versions'], $response->status());
            }

            return response()->json($response->json());
        } catch (\Throwable $e) {
            Log::error("[PluginMarket] pluginVersions exception: " . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch versions'], 500);
        }
    }

    /**
     * Override show method to set proper title/description
     */
    public function show($id, Content $content)
    {
        PluginMarketClient::warnApiKey();
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
        // Local-only plugin (market_deleted): load from DB instead of calling marketplace API
        if (str_starts_with((string) $id, 'local-')) {
            $localDbId   = substr($id, strlen('local-'));
            $localPlugin = Plugin::find($localDbId);

            if (!$localPlugin) {
                admin_toastr(exmtrans('plugin.market.plugin_not_found'), 'error');
                return redirect(admin_url('plugin-market'));
            }

            $raw = $localPlugin->getAttributes();
            foreach ($raw as $k => $v) {
                if (is_array($v)) {
                    $raw[$k] = implode(',', array_map('strval', $v));
                } elseif (is_object($v)) {
                    $raw[$k] = (string) $v;
                }
            }

            $isDisabled = isset($localPlugin->active_flg) && !(bool) $localPlugin->active_flg;

            $plugin = $this->normalizePluginForDisplay(array_merge($raw, [
                'is_installed'    => true,
                'current_version' => $localPlugin->version ?? null,
                'has_update'      => false,
                'plugin_status'   => $this->resolvePluginStatus(
                    isInstalled: true,
                    isOnMarket: false,
                    isDisabled: $isDisabled,
                    versionExists: false
                ),
            ]));
            // dd($plugin);
            return view('exment::plugin.market.detail', compact('plugin'));
        }

        // Marketplace plugin: call API (Bearer auth via PluginMarketClient)
        try {
            $response = PluginMarketClient::make()
                ->get("{$this->getRepoUrl()}/{$id}");

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
        PluginMarketClient::warnApiKey();
        try {
            $versionId = $request->input('version'); // Selected version ID

            Log::info("[PluginMarket] Install request", [
                'plugin_id' => $id,
                'version_id' => $versionId,
                'marketplace_url' => $this->getMarketplaceUrl(),
            ]);

            // Get plugin info from marketplace (Bearer auth via PluginMarketClient)
            $pluginResponse = PluginMarketClient::make()
                ->get("{$this->getRepoUrl()}/{$id}");

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
            $versionResponse = PluginMarketClient::make()
                ->get("{$this->getRepoUrl()}/{$id}/versions");

            if ($versionResponse->failed()) {
                if ($versionResponse->status() === 404) {
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

            Log::info("[PluginMarket] Downloading plugin", [
                'download_url' => $downloadUrl,
            ]);

            // Download plugin file
            try {
                $zipResp = PluginMarketClient::make(timeout: 60, retry: 1, retryDelay: 200)
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

                // Mark as installed from marketplace (not local upload)
                $installed = Plugin::where('plugin_name', $pluginName)->latest()->first();
                if ($installed) {
                    $installed->local = false;
                    $installed->save();
                }

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
     * Uninstall plugin from system.
     *
     * The view always passes the id as "local-{db_id}" so we resolve the
     * plugin entirely from the local database — no marketplace API call is
     * ever made.  This means uninstall works correctly even when the plugin
     * has been removed from the marketplace (market_deleted).
     */
    public function uninstall(Request $request, $id)
    {
        try {
            // Resolve local DB id: accept both "local-{n}" (from view) and a
            // bare integer as a safety fallback.
            $localDbId = str_starts_with((string) $id, 'local-')
                ? substr($id, strlen('local-'))
                : $id;

            $installedPlugin = Plugin::find($localDbId);

            if (!$installedPlugin) {
                return response()->json(['error' => exmtrans('plugin.market.message.plugin_not_installed')], 404);
            }

            $pluginName = $installedPlugin->plugin_name;

            // Delete plugin folder
            $disk   = Storage::disk(Define::DISKNAME_ADMIN);
            $folder = $installedPlugin->getPath();
            if ($disk->exists($folder)) {
                $disk->deleteDirectory($folder);
            }

            // Delete plugin record from database
            $installedPlugin->delete();

            return response()->json([
                'result' => true,
                'status' => true,
                'swal'   => exmtrans('plugin.market.message.uninstall_success', ['name' => $pluginName])
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
