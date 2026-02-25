# Plugin Market - Installation & Update Process

## Installation Flow

### 1. Installation Request
**Endpoint**: `POST /admin/plugin-market/{id}/install`

**Request Parameters**:
```php
$request->input('version')  // Required: Version ID to install
```

### 2. Installation Steps

#### Step 2.1: Validate Plugin Existence
```php
// Get plugin info from marketplace
$pluginResponse = Http::withoutVerifying()
    ->timeout(30)
    ->connectTimeout(10)
    ->get("{$this->getRepoUrl()}/{$id}", $queryParams);

if ($pluginResponse->failed()) {
    return response()->json(['error' => 'plugin_not_found'], 404);
}

$pluginData = $pluginResponse->json();
$pluginName = $pluginData['plugin_name'] ?? null;
```

#### Step 2.2: Check for Existing Installation
```php
// Determine if this is an update
$installedPlugin = Plugin::where('plugin_name', $pluginName)->first();
$isUpdate = $installedPlugin ? true : false;

if ($isUpdate) {
    Log::info("[PluginMarket] This is an update", [
        'current_version' => $installedPlugin->version,
    ]);
}
```

#### Step 2.3: Validate Version Selection
```php
if (empty($versionId)) {
    return response()->json(['error' => 'please_select_version'], 400);
}

// Get version information
$versionResponse = Http::withoutVerifying()
    ->timeout(30)
    ->connectTimeout(10)
    ->get("{$this->getRepoUrl()}/{$id}/versions", $queryParams);

$versionsData = $versionResponse->json();
$selectedVersion = collect($versionsData['versions'] ?? [])
    ->firstWhere('id', (int)$versionId);

if (!$selectedVersion) {
    return response()->json(['error' => 'version_not_found'], 404);
}
```

#### Step 2.4: Prepare Download URL
```php
$downloadUrl = $selectedVersion['download_url'];

// Option 1: Resign S3 signed URL (if configured)
if ((bool) config('exment.market_resign_signed_download_url', false)) {
    $downloadUrl = $this->resignMarketplaceSignedUrl(
        $downloadUrl, 
        $tenantUuid, 
        10  // validity minutes
    );
}
// Option 2: Simple append tenant UUID
else {
    $downloadUrl = $this->appendTenantUuidToUrl($downloadUrl, $tenantUuid);
}
```

#### Step 2.5: Download Plugin ZIP
```php
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
    
    return response()->json(['error' => 'download_failed'], 500);
}

if ($zipResp->failed()) {
    // Log detailed error info
    $contentType = $zipResp->header('Content-Type');
    $bodyPreview = $this->getResponseBodyPreview($zipResp);
    
    Log::warning('[PluginMarket] Download failed', [
        'status' => $zipResp->status(),
        'content_type' => $contentType,
        'body_preview' => $bodyPreview,
    ]);
    
    return response()->json(['error' => 'download_failed'], 500);
}

$zipBytes = $zipResp->body();
```

#### Step 2.6: Save to Temporary Location
```php
$tmpPath = 'tmp/' . Str::random(10) . '.zip';
Storage::disk('local')->put($tmpPath, $zipBytes);
$fullPath = Storage::disk('local')->path($tmpPath);
```

#### Step 2.7: Remove Old Version (if Update)
```php
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
```

#### Step 2.8: Install New Version
```php
try {
    PluginInstaller::uploadPlugin(new \Illuminate\Http\File($fullPath));
    
    // Clean up temporary file
    Storage::disk('local')->delete($tmpPath);
    
    $message = $isUpdate 
        ? exmtrans('plugin.market.message.update_success') 
        : exmtrans('plugin.market.message.install_success', ['name' => $pluginName]);
    
    return response()->json([
        'success' => true, 
        'message' => $message
    ]);
} catch (\Throwable $installError) {
    // Clean up temporary file
    Storage::disk('local')->delete($tmpPath);
    
    Log::error("[PluginMarket] Installation failed: " . $installError->getMessage());
    
    return response()->json([
        'error' => 'install_failed: ' . $installError->getMessage()
    ], 500);
}
```

## Update Flow

Update flow hoàn toàn giống Installation flow, chỉ khác:

1. **Detect Update**: `$isUpdate = true` khi plugin đã tồn tại
2. **Remove Old Version**: Xóa plugin cũ trước khi install mới
3. **Success Message**: Hiển thị "Update success" thay vì "Install success"

## Uninstallation Flow

**Endpoint**: `POST /admin/plugin-market/{id}/uninstall`

### Uninstall Steps

#### Step 1: Get Plugin Info from Marketplace
```php
$pluginResponse = Http::withoutVerifying()
    ->timeout(30)
    ->connectTimeout(10)
    ->get("{$this->getRepoUrl()}/{$id}", $queryParams);

if ($pluginResponse->failed()) {
    return response()->json(['error' => 'plugin_not_found'], 404);
}

$pluginData = $pluginResponse->json();
$pluginName = $pluginData['plugin_name'] ?? null;
```

#### Step 2: Find Installed Plugin
```php
$installedPlugin = Plugin::where('plugin_name', $pluginName)->first();

if (!$installedPlugin) {
    return response()->json(['error' => 'plugin_not_installed'], 404);
}
```

#### Step 3: Delete Plugin Files
```php
$disk = Storage::disk(Define::DISKNAME_ADMIN);
$folder = $installedPlugin->getPath();  // e.g., "plugins/PluginName/"

if ($disk->exists($folder)) {
    $disk->deleteDirectory($folder);
}
```

#### Step 4: Delete Database Record
```php
$installedPlugin->delete();
```

#### Step 5: Return Success
```php
return response()->json([
    'result' => true,
    'status' => true,
    'swal' => exmtrans('plugin.market.message.uninstall_success', ['name' => $pluginName])
]);
```

## Version Comparison Logic

### Detect Update Available

```php
// In PluginMarketController::index()
foreach ($plugins as $i => $p) {
    $nameKey = strtolower($p['plugin_name'] ?? '');
    
    $isInstalled = $installed->has($nameKey) && !empty($nameKey);
    $plugins[$i]['is_installed'] = $isInstalled;
    
    if ($isInstalled) {
        $installedVersion = $installed->get($nameKey)->version ?? null;
        $plugins[$i]['current_version'] = $installedVersion;
        
        // Version comparison
        $plugins[$i]['has_update'] = $installedVersion && isset($p['version'])
            ? version_compare($installedVersion, $p['version'], '<')
            : false;
    } else {
        $plugins[$i]['current_version'] = null;
        $plugins[$i]['has_update'] = false;
    }
}
```

### PHP version_compare()
Sử dụng built-in function `version_compare()`:
- Returns `true` if first version < second version
- Hỗ trợ semver: `1.0.0`, `1.2.3-beta`, `2.0.0-rc1`, etc.

**Examples**:
```php
version_compare('1.0.0', '1.1.0', '<')  // true - có update
version_compare('1.5.2', '1.5.2', '<')  // false - đã latest
version_compare('2.0.0', '1.9.9', '<')  // false - đã mới hơn
```

## Error Handling

### Common Error Scenarios

#### 1. Network Connection Failure
```php
try {
    $response = Http::withoutVerifying()
        ->timeout(30)
        ->connectTimeout(10)
        ->get($url);
} catch (\Illuminate\Http\Client\ConnectionException $e) {
    Log::error("[PluginMarket] Connection error: " . $e->getMessage());
    return redirect(admin_url('plugin-market'))
        ->with('error', exmtrans('plugin.market.message.connection_error'));
}
```

#### 2. Invalid Response Format
```php
if ($response->failed()) {
    Log::error("[PluginMarket] API returned error", [
        'status' => $response->status(),
        'url' => $url,
    ]);
    return response()->json(['error' => 'api_error'], $response->status());
}

$data = $response->json();
if (!is_array($data)) {
    Log::warning('[PluginMarket] Invalid response format');
    return response()->json(['error' => 'invalid_response'], 500);
}
```

#### 3. Download Corruption
```php
$zipBytes = $zipResp->body();

// Validate ZIP format (basic check)
if (strlen($zipBytes) < 100) {  // Too small to be valid ZIP
    Log::error('[PluginMarket] Downloaded file too small');
    return response()->json(['error' => 'corrupt_download'], 500);
}

// ZIP magic bytes: PK (0x50 0x4B)
if (substr($zipBytes, 0, 2) !== 'PK') {
    Log::error('[PluginMarket] Not a valid ZIP file');
    return response()->json(['error' => 'invalid_zip_format'], 500);
}
```

#### 4. Installation Failure
```php
try {
    PluginInstaller::uploadPlugin(new \Illuminate\Http\File($fullPath));
} catch (\Exceedone\Exment\Exceptions\PluginInstallException $e) {
    // Specific plugin install errors
    Log::error('[PluginMarket] Plugin install error: ' . $e->getMessage());
    return response()->json(['error' => $e->getMessage()], 400);
} catch (\Throwable $e) {
    // Unexpected errors
    Log::error('[PluginMarket] Unexpected install error: ' . $e->getMessage());
    return response()->json(['error' => 'install_failed'], 500);
}
```

## Retry Logic

### HTTP Request Retry
```php
Http::withoutVerifying()
    ->timeout(60)
    ->connectTimeout(10)
    ->retry(1, 200)  // Retry 1 time, wait 200ms between attempts
    ->get($downloadUrl);
```

### When to Retry
- Network timeouts
- Transient 5xx errors
- Connection resets

### When NOT to Retry
- 4xx client errors (bad request, not found)
- Authentication failures
- Validation errors

## Temporary File Management

### Storage Location
```php
// Use Laravel local storage
$tmpPath = 'tmp/' . Str::random(10) . '.zip';
Storage::disk('local')->put($tmpPath, $zipBytes);

// Typically: storage/app/tmp/random10chars.zip
$fullPath = Storage::disk('local')->path($tmpPath);
```

### Cleanup Strategy
```php
// Always cleanup in finally or after operation
try {
    PluginInstaller::uploadPlugin(new \Illuminate\Http\File($fullPath));
} catch (\Throwable $e) {
    Log::error('Install failed: ' . $e->getMessage());
    throw $e;
} finally {
    // Ensure cleanup even on error
    if (Storage::disk('local')->exists($tmpPath)) {
        Storage::disk('local')->delete($tmpPath);
    }
}
```

### Automatic Cleanup
Consider adding a scheduled job to clean old temp files:
```php
// In App\Console\Kernel
$schedule->command('cleanup:temp-plugins')->daily();
```

## Security Considerations

### 1. ZIP Bomb Protection
Add validation for ZIP size before extraction:
```php
$zipSize = Storage::disk('local')->size($tmpPath);
$maxSize = 100 * 1024 * 1024;  // 100 MB limit

if ($zipSize > $maxSize) {
    Storage::disk('local')->delete($tmpPath);
    return response()->json(['error' => 'file_too_large'], 400);
}
```

### 2. Path Traversal Protection
Validate plugin files don't contain `../` paths:
```php
$zip = new ZipArchive();
$zip->open($fullPath);

for ($i = 0; $i < $zip->numFiles; $i++) {
    $filename = $zip->getNameIndex($i);
    
    if (strpos($filename, '..') !== false || strpos($filename, '/..') !== false) {
        $zip->close();
        Storage::disk('local')->delete($tmpPath);
        return response()->json(['error' => 'invalid_zip_contents'], 400);
    }
}
```

### 3. Tenant Isolation
Always pass tenant UUID:
```php
$tenantUuid = $this->getTenantUuid();
$queryParams = [];
if (!empty($tenantUuid)) {
    $queryParams['tenant_uuid'] = $tenantUuid;
}

// Marketplace API validates tenant has access to this plugin
$response = Http::get($apiUrl, $queryParams);
```

## Performance Optimization

### 1. Parallel Version Check
When listing plugins, batch check versions:
```php
// Instead of N queries to get installed plugins
$installed = Plugin::all()->keyBy(function ($p) {
    return strtolower($p->plugin_name ?? '');
});

// Single query, create lookup index
```

### 2. Streaming Large Downloads
For large plugins:
```php
// Use stream to avoid memory issues
$response = Http::withoutVerifying()
    ->timeout(120)
    ->sink(Storage::disk('local')->path($tmpPath))  // Stream to file
    ->get($downloadUrl);
```

### 3. Background Processing
Consider queue for large plugins:
```php
// Dispatch to queue
dispatch(new InstallPluginJob($pluginId, $versionId, $userId));

// Return immediately
return response()->json([
    'queued' => true,
    'message' => 'Installation started in background'
]);
```

## Testing Examples

### Unit Test: Version Comparison
```php
public function test_version_comparison()
{
    $this->assertTrue(version_compare('1.0.0', '1.1.0', '<'));
    $this->assertFalse(version_compare('1.5.0', '1.5.0', '<'));
    $this->assertFalse(version_compare('2.0.0', '1.9.9', '<'));
}
```

### Integration Test: Install Flow
```php
public function test_plugin_installation()
{
    // Mock marketplace API
    Http::fake([
        '*/api/plugins/123' => Http::response(['plugin_name' => 'TestPlugin']),
        '*/api/plugins/123/versions' => Http::response([
            'versions' => [
                ['id' => 1, 'version' => '1.0.0', 'download_url' => 'http://...']
            ]
        ]),
        'http://...' => Http::response(file_get_contents('tests/fixtures/plugin.zip')),
    ]);
    
    $response = $this->post('/admin/plugin-market/123/install', [
        'version' => 1
    ]);
    
    $response->assertStatus(200);
    $response->assertJson(['success' => true]);
    
    $this->assertDatabaseHas('plugins', [
        'plugin_name' => 'TestPlugin'
    ]);
}
```
