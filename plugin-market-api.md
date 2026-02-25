# Plugin Market API Documentation

## External Marketplace API

Hệ thống tương tác với external marketplace API để fetch plugin data, versions, và license information.

### Base Configuration

```php
// config/exment.php
'market_plugin_url' => env('EXMENT_MARKET_PLUGIN_URL', 'https://exment.org'),
'market_tenant_uuid' => env('EXMENT_MARKET_TENANT_UUID', null),
'market_resign_signed_download_url' => env('EXMENT_MARKET_RESIGN_SIGNED_DOWNLOAD_URL', false),
```

### API Endpoints

#### 1. List All Plugins
**URL**: `GET {market_plugin_url}/api/plugins`

**Query Parameters**:
```php
[
    'tenant_uuid' => string,  // Optional but recommended for license info
    'type' => string,         // Optional: filter by plugin type
    'status' => string        // Optional: filter by status
]
```

**Response**:
```json
[
  {
    "id": 123,
    "uuid": "550e8400-e29b-41d4-a716-446655440000",
    "plugin_name": "AdvancedDataGrid",
    "plugin_view_name": "Advanced Data Grid",
    "plugin_types": "Page,Dashboard",
    "version": "2.1.0",
    "description": "Powerful data grid with advanced features",
    "author": "ExceedOne",
    "url": "https://exment.org/plugins/advanced-data-grid",
    "is_free": false,
    "price": 99.00,
    "currency": "USD",
    "check_status": "approved",
    
    // License info (only when tenant_uuid provided)
    "has_license": true,
    "expires_at": "2026-12-31T23:59:59Z",
    "is_expired": false,
    
    // Version info
    "versions": [
      {
        "id": 456,
        "version": "2.1.0",
        "is_latest": true,
        "release_date": "2026-02-01",
        "changelog": "Added new features..."
      },
      {
        "id": 455,
        "version": "2.0.5",
        "is_latest": false,
        "release_date": "2026-01-15"
      }
    ]
  }
]
```

#### 2. Get Plugin Detail
**URL**: `GET {market_plugin_url}/api/plugins/{id}`

**Query Parameters**:
```php
[
    'tenant_uuid' => string  // Optional
]
```

**Response**:
```json
{
  "id": 123,
  "uuid": "550e8400-e29b-41d4-a716-446655440000",
  "plugin_name": "AdvancedDataGrid",
  "plugin_view_name": "Advanced Data Grid",
  "plugin_types": "Page,Dashboard",
  "version": "2.1.0",
  "latest_version": "2.1.0",
  "description": "Powerful data grid with advanced features",
  "long_description": "## Features\n\n- Feature 1\n- Feature 2\n...",
  "screenshots": [
    "https://cdn.exment.org/screenshots/123/1.png",
    "https://cdn.exment.org/screenshots/123/2.png"
  ],
  "author": "ExceedOne",
  "author_email": "support@exceedone.co.jp",
  "url": "https://exment.org/plugins/advanced-data-grid",
  "documentation_url": "https://docs.exment.org/plugins/advanced-data-grid",
  "is_free": false,
  "price": 99.00,
  "currency": "USD",
  "check_status": "approved",
  "downloads": 1523,
  "rating": 4.8,
  "reviews_count": 42,
  
  // Requirements
  "minimum_exment_version": "5.0.0",
  "maximum_exment_version": null,
  "php_version": ">=8.0",
  
  // License info (only when tenant_uuid provided)
  "has_license": true,
  "license_type": "annual",
  "purchase_date": "2026-01-01",
  "expires_at": "2027-01-01T00:00:00Z",
  "is_expired": false,
  "days_until_expiry": 311,
  
  // Version history
  "versions": [...]
}
```

#### 3. Get Plugin Versions
**URL**: `GET {market_plugin_url}/api/plugins/{id}/versions`

**Query Parameters**:
```php
[
    'tenant_uuid' => string  // Optional
]
```

**Response**:
```json
{
  "plugin_id": 123,
  "plugin_name": "AdvancedDataGrid",
  "versions": [
    {
      "id": 456,
      "version": "2.1.0",
      "is_latest": true,
      "release_date": "2026-02-01T10:00:00Z",
      "changelog": "## Version 2.1.0\n\n### Added\n- New feature X\n\n### Fixed\n- Bug Y",
      "download_url": "https://marketplace.exment.org/downloads/123/456/download?signature=...",
      "download_count": 523,
      "file_size": 2457600,  // bytes
      "minimum_exment_version": "5.0.0",
      "php_version": ">=8.0",
      "requires_license": true
    },
    {
      "id": 455,
      "version": "2.0.5",
      "is_latest": false,
      "release_date": "2026-01-15T14:30:00Z",
      "changelog": "Bug fixes",
      "download_url": "https://marketplace.exment.org/downloads/123/455/download?signature=...",
      "download_count": 891,
      "file_size": 2401280
    }
  ]
}
```

#### 4. Download Plugin Version
**URL**: `GET {market_plugin_url}/api/plugins/{plugin_id}/versions/{version_id}/download`

**Query Parameters**:
```php
[
    'tenant_uuid' => string,  // Required for paid plugins
    'signature' => string,    // Optional: pre-signed URL signature
    'expires' => int          // Optional: expiry timestamp
]
```

**Response**:
- Content-Type: `application/zip`
- Body: Binary ZIP file data

**Error Responses**:
```json
// 401 Unauthorized (no license)
{
  "error": "license_required",
  "message": "This plugin requires a valid license",
  "purchase_url": "https://marketplace.exment.org/plugins/123/purchase"
}

// 403 Forbidden (expired license)
{
  "error": "license_expired",
  "message": "Your license has expired",
  "expires_at": "2026-01-01T00:00:00Z",
  "renew_url": "https://marketplace.exment.org/licenses/renew"
}

// 404 Not Found
{
  "error": "version_not_found",
  "message": "Plugin version not found"
}
```

## Internal API (Exment Routes)

Routes được đăng ký trong `src/Providers/RouteServiceProvider.php`.

### 1. Browse Marketplace
**URL**: `GET /admin/plugin-market`

**Handler**: `PluginMarketController@index`

**Query Parameters**:
```php
[
    'type' => string,       // Filter by plugin type
    'status' => string,     // Filter by status
    'search' => string,     // Search query
    'page' => int,          // Page number (default: 1)
    'per_page' => int       // Items per page (default: 20, max: 200)
]
```

**Response**: HTML page với plugin grid

**Implementation**:
```php
public function index(Content $content)
{
    $request = request();
    $tenantUuid = $this->getTenantUuid();
    
    // Fetch from external marketplace API
    $response = Http::withoutVerifying()
        ->timeout(30)
        ->connectTimeout(10)
        ->get($this->getRepoUrl(), ['tenant_uuid' => $tenantUuid]);
    
    $plugins = collect($response->json());
    
    // Apply filters
    if ($type = $request->input('type')) {
        $plugins = $plugins->filter(function ($plugin) use ($type) {
            $pluginTypes = $plugin['plugin_types'] ?? '';
            return str_contains(strtolower($pluginTypes), strtolower($type));
        });
    }
    
    if ($status = $request->input('status')) {
        $plugins = $plugins->filter(function ($plugin) use ($status) {
            $checkStatus = strtolower($plugin['check_status'] ?? '');
            return $checkStatus === strtolower($status);
        });
    }
    
    // Enrich with local install info
    $installed = Plugin::all()->keyBy(function ($p) {
        return strtolower($p->plugin_name ?? '');
    });
    
    foreach ($plugins as $i => $p) {
        $nameKey = strtolower($p['plugin_name'] ?? '');
        $isInstalled = $installed->has($nameKey);
        
        $plugins[$i]['is_installed'] = $isInstalled;
        if ($isInstalled) {
            $installedVersion = $installed->get($nameKey)->version ?? null;
            $plugins[$i]['current_version'] = $installedVersion;
            $plugins[$i]['has_update'] = $installedVersion && isset($p['version'])
                ? version_compare($installedVersion, $p['version'], '<')
                : false;
        }
    }
    
    // Paginate
    $perPage = min((int) $request->input('per_page', 20), 200);
    $page = (int) $request->input('page', 1);
    $plugins = new LengthAwarePaginator(
        array_slice($plugins, ($page - 1) * $perPage, $perPage),
        count($plugins),
        $perPage,
        $page
    );
    
    return $content->title(exmtrans('plugin.market.title'))
        ->body(view('exment::plugin.market.index', compact('plugins', 'tenantUuid')));
}
```

### 2. Plugin Detail
**URL**: `GET /admin/plugin-market/{id}`

**Handler**: `PluginMarketController@show`

**Response**: HTML page với plugin detail

### 3. Install Plugin
**URL**: `POST /admin/plugin-market/{id}/install`

**Handler**: `PluginMarketController@install`

**Request Body**:
```json
{
  "version": 456  // Version ID to install
}
```

**Response**:
```json
// Success
{
  "success": true,
  "message": "Plugin 'AdvancedDataGrid' installed successfully"
}

// Error
{
  "error": "Download failed: Connection timeout"
}
```

**HTTP Status Codes**:
- `200 OK`: Installation successful
- `400 Bad Request`: Invalid request (missing version, etc.)
- `404 Not Found`: Plugin or version not found
- `500 Internal Server Error`: Installation failed

### 4. Update Plugin
**URL**: `POST /admin/plugin-market/{id}/update`

**Handler**: `PluginMarketController@update` (tương tự install)

**Request Body**:
```json
{
  "version": 457  // New version ID
}
```

**Response**: Tương tự install endpoint

### 5. Uninstall Plugin
**URL**: `POST /admin/plugin-market/{id}/uninstall`

**Handler**: `PluginMarketController@uninstall`

**Request Body**: None

**Response**:
```json
{
  "result": true,
  "status": true,
  "swal": "Plugin 'AdvancedDataGrid' has been uninstalled successfully"
}
```

### 6. Checkout Purchase
**URL**: `POST /admin/plugin-market/checkout/purchase`

**Handler**: `PluginMarketController@checkoutPurchase`

**Note**: Route được đăng ký nhưng implementation chưa có trong commit này.

## Helper Methods

### Get Repository URL

```php
protected function getRepoUrl(): string
{
    return rtrim(config('exment.market_plugin_url', 'https://exment.org'), '/') . '/api/plugins';
}
```

### Get Marketplace Base URL

```php
protected function getMarketplaceBaseUrl(): string
{
    return rtrim(config('exment.market_plugin_url', 'https://exment.org'), '/');
}
```

### Get Tenant UUID

```php
protected function getTenantUuid(): ?string
{
    $tenantUuid = config('exment.market_tenant_uuid');
    if (is_string($tenantUuid) && trim($tenantUuid) !== '') {
        return trim($tenantUuid);
    }
    return null;
}
```

### Append Tenant UUID to URL

```php
protected function appendTenantUuidToUrl(string $url, ?string $tenantUuid): string
{
    if (empty($tenantUuid)) {
        return $url;
    }
    
    $separator = str_contains($url, '?') ? '&' : '?';
    return $url . $separator . 'tenant_uuid=' . urlencode($tenantUuid);
}
```

### Resign Marketplace Signed URL

```php
protected function resignMarketplaceSignedUrl(string $url, ?string $tenantUuid, int $validityMinutes = 10): string
{
    // Parse original URL
    $parts = parse_url($url);
    $basePath = $parts['scheme'] . '://' . $parts['host'] . ($parts['path'] ?? '');
    
    // Generate new signature
    $expires = time() + ($validityMinutes * 60);
    $stringToSign = $basePath . '|' . $tenantUuid . '|' . $expires;
    $signature = hash_hmac('sha256', $stringToSign, config('app.key'));
    
    // Build new URL
    $newUrl = $basePath . '?tenant_uuid=' . urlencode($tenantUuid ?? '')
        . '&expires=' . $expires
        . '&signature=' . $signature;
    
    return $newUrl;
}
```

**Use Cases**:
- S3 signed URLs có expiry ngắn
- Cần extend expiry khi download lâu
- Security: prevent URL sharing

## PluginRepository Service

Service helper để fetch và cache plugin versions.

### Fetch Versions

```php
namespace Exceedone\Exment\Services\Plugin;

class PluginRepository
{
    public static function fetchVersions(): array
    {
        // Clear và rebuild cache
        cache()->forget('plugin_repo_versions');
        
        return cache()->remember('plugin_repo_versions', 300, function () {
            $marketplaceUrl = rtrim(config('exment.market_plugin_url', 'https://exment.org'), '/');
            $apiUrl = $marketplaceUrl . '/api/plugins';
            
            $tenantUuid = config('exment.market_tenant_uuid');
            $queryParams = [];
            if (is_string($tenantUuid) && strlen(trim($tenantUuid)) > 0) {
                $queryParams['tenant_uuid'] = trim($tenantUuid);
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
            
            // Transform and index by UUID
            return collect($plugins)->map(function ($plugin) use ($marketplaceUrl, $tenantUuid) {
                $pluginId = $plugin['id'] ?? $plugin['uuid'] ?? '';
                
                // Get latest version
                $versions = collect($plugin['versions'] ?? []);
                $latestVersion = $versions->firstWhere('is_latest', true) ?? $versions->first();
                
                $latestVersionName = $latestVersion['version']
                    ?? ($plugin['version'] ?? $plugin['latest_version'] ?? '');
                
                // Build download URL
                $downloadUrl = null;
                if ($latestVersion && isset($latestVersion['id'])) {
                    $downloadUrl = $marketplaceUrl . '/api/plugins/' . $pluginId 
                        . '/versions/' . $latestVersion['id'] . '/download';
                    
                    if (!empty($tenantUuid) && !str_contains($downloadUrl, 'tenant_uuid=')) {
                        $downloadUrl .= '?tenant_uuid=' . urlencode($tenantUuid);
                    }
                }
                
                return [
                    'uuid' => $plugin['uuid'] ?? $pluginId,
                    'plugin_name' => $plugin['plugin_name'] ?? '',
                    'latest_version' => $latestVersionName,
                    'download_url' => $downloadUrl,
                    'marketplace_id' => $pluginId,
                ];
            })->keyBy('uuid')->toArray();
        });
    }
}
```

**Cache Strategy**:
- Cache key: `plugin_repo_versions`
- TTL: 300 seconds (5 minutes)
- Cleared on every call (fresh data)

## HTTP Client Configuration

### Timeout Settings

```php
Http::withoutVerifying()
    ->timeout(30)           // Total request timeout: 30 seconds
    ->connectTimeout(10)    // Connection timeout: 10 seconds
    ->retry(2, 100)         // Retry 2 times, wait 100ms between attempts
    ->get($url);
```

**Rationale**:
- `timeout(30)`: For plugin list/detail (fast responses)
- `timeout(60)`: For downloads (large files)
- `connectTimeout(10)`: Fail fast if server unreachable
- `retry(2, 100)`: Handle transient failures

### SSL Verification

```php
Http::withoutVerifying()
```

**⚠️ Security Warning**: `withoutVerifying()` disables SSL certificate verification.

**Recommendations**:
1. **Development**: OK to use for testing
2. **Production**: Should verify certificates
3. **Fix**: Remove `withoutVerifying()` or configure proper CA bundle

```php
// Recommended for production
Http::timeout(30)
    ->connectTimeout(10)
    ->get($url);
```

## Error Response Handling

### Response Body Preview

```php
protected function getResponseBodyPreview(\Illuminate\Http\Client\Response $response): string
{
    $contentType = $response->header('Content-Type');
    
    try {
        // JSON response
        if (is_string($contentType) && str_contains(strtolower($contentType), 'application/json')) {
            return json_encode($response->json());
        }
        
        // Binary/text response
        $body = $response->body();
        if (!is_string($body)) {
            $body = '';
        }
        
        // Sanitize non-printable chars
        $bodyPreview = substr($body, 0, 2000);
        $bodyPreview = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '.', $bodyPreview);
        $bodyPreview = Str::limit($bodyPreview, 2000);
        
        return $bodyPreview;
    } catch (\Throwable $e) {
        return '<<unable to read response body: ' . $e->getMessage() . '>>';
    }
}
```

**Usage**:
```php
if ($response->failed()) {
    Log::warning('[PluginMarket] Download failed', [
        'status' => $response->status(),
        'content_type' => $response->header('Content-Type'),
        'body_preview' => $this->getResponseBodyPreview($response),
    ]);
}
```

## Integration Testing

### Mock HTTP Responses

```php
use Illuminate\Support\Facades\Http;

Http::fake([
    '*/api/plugins' => Http::response([
        [
            'id' => 1,
            'plugin_name' => 'TestPlugin',
            'version' => '1.0.0',
            'is_free' => true,
        ]
    ], 200),
    
    '*/api/plugins/1' => Http::response([
        'id' => 1,
        'plugin_name' => 'TestPlugin',
        'version' => '1.0.0',
    ], 200),
    
    '*/api/plugins/1/versions' => Http::response([
        'versions' => [
            [
                'id' => 1,
                'version' => '1.0.0',
                'download_url' => 'https://example.com/download.zip',
            ]
        ]
    ], 200),
    
    'https://example.com/download.zip' => Http::response(
        file_get_contents('tests/fixtures/test-plugin.zip'),
        200,
        ['Content-Type' => 'application/zip']
    ),
]);

$response = $this->post('/admin/plugin-market/1/install', ['version' => 1]);
$response->assertStatus(200);
```

### Test Error Scenarios

```php
// Connection timeout
Http::fake([
    '*/api/plugins' => function () {
        throw new \Illuminate\Http\Client\ConnectionException('Connection timeout');
    }
]);

// API error
Http::fake([
    '*/api/plugins' => Http::response(['error' => 'Server error'], 500)
]);

// Invalid JSON
Http::fake([
    '*/api/plugins' => Http::response('Not JSON', 200, ['Content-Type' => 'text/plain'])
]);
```

## Rate Limiting Considerations

### Client-Side Throttling

```php
// License sync: max 1 request per 24 hours
(new PluginLicenseSyncService())->syncThrottled(1440);

// Version cache: 5 minutes
cache()->remember('plugin_repo_versions', 300, function () {
    // Fetch from API
});
```

### Server-Side Rate Limits

Marketplace API có thể có rate limits:
- Per tenant: 100 requests/minute
- Per IP: 1000 requests/hour
- Download: 10 concurrent downloads

**Best Practices**:
- Cache aggressively
- Batch requests khi possible
- Implement exponential backoff
- Handle 429 Too Many Requests

## Security Best Practices

### 1. Validate Tenant UUID
```php
if (!empty($tenantUuid) && !preg_match('/^[a-f0-9-]{36}$/i', $tenantUuid)) {
    throw new \InvalidArgumentException('Invalid tenant UUID format');
}
```

### 2. Validate Download URLs
```php
$parsedUrl = parse_url($downloadUrl);
$allowedHosts = config('exment.market_allowed_hosts', ['marketplace.exment.org', 's3.amazonaws.com']);

if (!in_array($parsedUrl['host'], $allowedHosts)) {
    throw new \Exception('Download URL host not allowed');
}
```

### 3. Verify ZIP Integrity
```php
$hash = hash('sha256', $zipBytes);
$expectedHash = $selectedVersion['file_hash'] ?? null;

if ($expectedHash && $hash !== $expectedHash) {
    throw new \Exception('Downloaded file hash mismatch');
}
```

### 4. Sandbox Plugin Execution
- Validate config.json before loading
- Scan for malicious code patterns
- Run in isolated environment
- Monitor resource usage
