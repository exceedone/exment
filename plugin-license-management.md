# Plugin License Management System

## Tổng quan

Hệ thống quản lý license tự động cho các plugin có phí (paid plugins), đảm bảo chỉ những tenant có license hợp lệ mới có thể sử dụng plugin.

## Thành phần chính

### 1. PluginLicenseSyncService
Location: `src/Services/Plugin/PluginLicenseSyncService.php` (351 dòng)

Service chính xử lý toàn bộ logic sync và quản lý license.

### 2. PluginLicenseSync Middleware
Location: `src/Middleware/PluginLicenseSync.php` (32 dòng)

Middleware tự động chạy mọi authenticated request để sync license.

## License Sync Flow

### 1. Middleware Trigger

```php
// src/Middleware/PluginLicenseSync.php
public function handle(Request $request, Closure $next)
{
    try {
        // Run after authentication on all admin requests
        if (\Exment::user()) {
            (new PluginLicenseSyncService())->syncThrottled(1440);  // 24 hours
        }
    } catch (\Throwable $e) {
        Log::warning('[PluginLicenseSync] Failed: ' . $e->getMessage());
    }
    
    return $next($request);
}
```

**Đặc điểm**:
- Chỉ chạy khi user đã authenticated
- Silent failure - không block request nếu sync fail
- Throttled - mặc định 24 giờ chạy 1 lần

### 2. Throttled Sync

```php
public function syncThrottled(int $minutes = 1440): void
{
    $cacheKey = $this->getThrottleCacheKey();  // 'exment_plugin_license_sync_last_run'
    
    if (Cache::has($cacheKey)) {
        return;  // Skip if recently synced
    }
    
    // Set cache early to prevent stampede on concurrent requests
    Cache::put($cacheKey, now(), now()->addMinutes(max(1, $minutes)));
    
    $this->sync();
}
```

**Throttle Strategy**:
- Cache key: `exment_plugin_license_sync_last_run`
- Default interval: 1440 minutes (24 hours)
- Stampede prevention: Set cache BEFORE syncing
- Per-system throttle (not per-user)

### 3. Main Sync Logic

```php
public function sync(): void
{
    // Step 1: Fetch marketplace plugins with license info
    $marketByName = $this->fetchMarketplacePluginsByName();
    if ($marketByName === null) {
        return;  // Skip if marketplace unavailable or non-tenant system
    }
    
    $changedCount = 0;
    
    // Step 2: Loop through all installed plugins
    foreach (Plugin::all() as $installedPlugin) {
        $nameKey = strtolower($installedPlugin->plugin_name ?? '');
        
        if ($nameKey === '' || !$marketByName->has($nameKey)) {
            continue;  // Skip if not in marketplace
        }
        
        $market = $marketByName->get($nameKey);
        
        // Step 3: Skip free plugins
        if (!$this->isPaidPlugin($market)) {
            continue;
        }
        
        // Step 4: Check license validity
        $hasLicense = (bool) ($market['has_license'] ?? false);
        $isExpiredOverGrace = $this->isExpiredOverGraceWeek($market);
        $isValid = $hasLicense && !$isExpiredOverGrace;
        
        // Step 5: Send warning email if in grace period
        if ($hasLicense && !$isExpiredOverGrace) {
            $expiresAt = $this->getExpiresAt($market);
            if ($expiresAt instanceof Carbon && $expiresAt->isPast()) {
                $this->sendExpiryWarningEmail($installedPlugin, $market, $expiresAt);
            }
        }
        
        // Step 6: Get current disabled state
        $options = is_array($installedPlugin->options) ? $installedPlugin->options : [];
        $disabledByLicense = (bool) array_get($options, 'disabled_by_license', false);
        
        // Step 7: Disable plugin if license invalid
        if (!$isValid) {
            if (boolval($installedPlugin->active_flg) || !$disabledByLicense) {
                $installedPlugin->active_flg = 0;
                $options['disabled_by_license'] = true;
                $installedPlugin->options = $options;
                $installedPlugin->save();
                $changedCount++;
            }
            continue;
        }
        
        // Step 8: Re-enable plugin if was disabled by license but now valid
        if ($disabledByLicense && !boolval($installedPlugin->active_flg)) {
            $installedPlugin->active_flg = 1;
            unset($options['disabled_by_license']);
            $installedPlugin->options = $options;
            $installedPlugin->save();
            $changedCount++;
        }
    }
    
    // Step 9: Clear cache if any plugins changed
    if ($changedCount > 0) {
        Plugin::clearCacheTrait();
        Log::info('[PluginLicenseSync] Synced plugin activation', ['changed' => $changedCount]);
    }
}
```

## License States

### 1. Free Plugin
```php
protected function isPaidPlugin(array $market): bool
{
    $isFree = (bool) ($market['is_free'] ?? ((float) ($market['price'] ?? 0) <= 0));
    return !$isFree;
}
```

**Logic**:
- Check `is_free` flag first
- Fallback to `price <= 0`
- Free plugins: không enforce license

### 2. Paid Plugin - Valid License
```php
$hasLicense = true
$isExpiredOverGrace = false
→ Plugin enabled, no action
```

### 3. Paid Plugin - Expired (Grace Period)
```php
$hasLicense = true
$expiresAt < now() && expiresAt + 7 days > now()
→ Plugin still enabled, send warning email
```

**Grace Period**: 7 ngày

### 4. Paid Plugin - Expired (Over Grace)
```php
$hasLicense = true
$expiresAt + 7 days < now()
→ Plugin auto-disabled
```

### 5. Paid Plugin - No License
```php
$hasLicense = false
→ Plugin auto-disabled
```

## Expiry Detection

### Get Expiry Date

```php
protected function getExpiresAt(array $market): ?Carbon
{
    // Try multiple possible field names
    $candidate = $market['expires_at']
        ?? $market['expired_at']
        ?? $market['license_expires_at']
        ?? ($market['license_info']['expires_at'] ?? null)
        ?? ($market['license']['expires_at'] ?? null);
    
    if (is_string($candidate) && trim($candidate) !== '') {
        try {
            return Carbon::parse($candidate);
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    // Support Unix timestamp
    if (is_int($candidate) || (is_string($candidate) && ctype_digit($candidate))) {
        try {
            return Carbon::createFromTimestamp((int) $candidate);
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    return null;
}
```

**Supported Formats**:
- ISO 8601: `2026-12-31T23:59:59Z`
- Date string: `2026-12-31`
- Unix timestamp: `1735689599`

### Check Expired Over Grace

```php
protected function isExpiredOverGraceWeek(array $market): bool
{
    $expiresAt = $this->getExpiresAt($market);
    
    if ($expiresAt instanceof Carbon) {
        return $expiresAt->copy()->addDays(7)->isPast();
    }
    
    // Fallback: if marketplace explicitly says expired but no parsable date
    return (bool) ($market['is_expired'] ?? false);
}
```

**Examples**:
```php
// Expires: 2026-02-20, Today: 2026-02-24 (4 days after)
$expiresAt->addDays(7) = 2026-02-27
isPast() = false
→ Still in grace period, keep enabled

// Expires: 2026-02-10, Today: 2026-02-24 (14 days after)
$expiresAt->addDays(7) = 2026-02-17
isPast() = true
→ Over grace period, disable plugin
```

## Warning Email System

### Send Warning Email

```php
protected function sendExpiryWarningEmail(Plugin $installedPlugin, array $market, Carbon $expiresAt): void
{
    $pluginName = (string) ($installedPlugin->plugin_name ?? '');
    
    // Step 1: Check if already sent today
    $cacheKey = $this->getWarningSentCacheKey($pluginName);
    if (Cache::has($cacheKey)) {
        return;  // Already sent today
    }
    
    // Step 2: Calculate days until disable
    $disableAt = $expiresAt->copy()->addDays(7);
    $daysLeft = now()->diffInDays($disableAt, false);
    
    if ($daysLeft < 0) {
        return;  // Already past disable date
    }
    
    // Step 3: Get admin email addresses
    $recipients = $this->getWarningRecipients();
    if (empty($recipients)) {
        return;  // No admins to notify
    }
    
    // Step 4: Prepare email content
    $pluginLabel = (string) ($installedPlugin->plugin_view_name ?? $installedPlugin->plugin_name ?? 'Plugin');
    $marketUrl = $this->getMarketplaceBaseUrl() . '/plugins';
    
    $subject = exmtrans('plugin.message.license_expired_warning_subject', [
        'plugin' => $pluginLabel,
    ]);
    
    $body = exmtrans('plugin.message.license_expired_warning_body', [
        'plugin' => $pluginLabel,
        'expires_at' => $expiresAt->toDateString(),
        'disable_at' => $disableAt->toDateString(),
        'days_left' => (string) $daysLeft,
        'market_url' => $marketUrl,
    ]);
    
    // Step 5: Send email
    try {
        Mail::raw((string) $body, function ($message) use ($recipients, $subject) {
            $message->to($recipients)->subject((string) $subject);
        });
        
        // Step 6: Mark as sent for today (cache until end of day)
        Cache::put($cacheKey, now()->toISOString(), now()->endOfDay());
    } catch (TransportExceptionInterface $e) {
        Log::warning('[PluginLicenseSync] Warning email transport failed: ' . $e->getMessage());
    } catch (\Throwable $e) {
        Log::warning('[PluginLicenseSync] Warning email failed: ' . $e->getMessage());
    }
}
```

### Email Throttling

```php
protected function getWarningSentCacheKey(string $pluginName): string
{
    $date = now()->toDateString();  // e.g., "2026-02-24"
    return 'exment_plugin_license_warning_sent:' . sha1(strtolower($pluginName) . '|' . $date);
}
```

**Throttle Strategy**:
- 1 email per plugin per day
- Cache key includes date → auto-reset daily
- Cache expires at end of day
- Prevents spam on multiple page loads

### Get Warning Recipients

```php
protected function getWarningRecipients(): array
{
    try {
        // Get system admin user IDs from config
        $adminIds = System::system_admin_users() ?? [];
        if (!is_array($adminIds) || empty($adminIds)) {
            return [];
        }
        
        // Load user models
        $userTable = CustomTable::getEloquent(SystemTableName::USER);
        $users = collect($adminIds)
            ->map(function ($id) use ($userTable) {
                return $userTable ? $userTable->getValueModel($id) : null;
            })
            ->filter();
        
        // Extract email addresses
        return array_values(array_filter(NotifyService::getAddresses($users->all())));
    } catch (\Throwable $e) {
        Log::warning('[PluginLicenseSync] Failed to resolve warning recipients: ' . $e->getMessage());
        return [];
    }
}
```

**Email Recipients**:
- Only system administrators (configured in Exment)
- Extracted via NotifyService
- Silent failure if resolution fails

## Marketplace API Integration

### Fetch Plugin License Data

```php
public function fetchMarketplacePluginsByName(): ?Collection
{
    // Use cached result if available
    if ($this->marketPluginsByName !== null) {
        return $this->marketPluginsByName;
    }
    
    // Get tenant UUID
    $tenantUuid = $this->getTenantUuid();
    if ($tenantUuid === null) {
        // OSS: marketplace doesn't return paid items; no enforcement
        $this->marketPluginsByName = null;
        return null;
    }
    
    try {
        $resp = Http::withoutVerifying()
            ->timeout(15)
            ->connectTimeout(5)
            ->retry(1, 100)
            ->get($this->getMarketplacePluginsApiUrl(), ['tenant_uuid' => $tenantUuid]);
        
        if (!$resp->ok()) {
            Log::info('[PluginLicenseSync] Marketplace license check skipped (API not ok)', [
                'status' => $resp->status(),
                'url' => $this->getMarketplacePluginsApiUrl(),
            ]);
            $this->marketPluginsByName = null;
            return null;
        }
        
        $marketPlugins = $resp->json();
        if (!is_array($marketPlugins)) {
            Log::warning('[PluginLicenseSync] Marketplace returned invalid data');
            $this->marketPluginsByName = null;
            return null;
        }
        
        // Index by plugin_name (case-insensitive)
        $this->marketPluginsByName = collect($marketPlugins)->keyBy(function ($p) {
            return strtolower($p['plugin_name'] ?? '');
        });
        
        return $this->marketPluginsByName;
    } catch (\Throwable $e) {
        Log::warning('[PluginLicenseSync] Marketplace fetch failed: ' . $e->getMessage());
        $this->marketPluginsByName = null;
        return null;
    }
}
```

**API Response Structure**:
```json
[
  {
    "plugin_name": "AdvancedDataGrid",
    "is_free": false,
    "price": 99.00,
    "has_license": true,
    "expires_at": "2026-12-31T23:59:59Z",
    "is_expired": false,
    ...
  },
  {
    "plugin_name": "BasicExporter",
    "is_free": true,
    "price": 0,
    ...
  }
]
```

### Tenant UUID Configuration

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

**Config**: `config/exment.php` → `market_tenant_uuid`

**Behavior**:
- If `tenant_uuid` is null/empty: Không enforce license (OSS mode)
- If `tenant_uuid` is set: Full license enforcement

## Block Manual Activation

### Check Before Activation

```php
public function shouldBlockActivation(string $pluginName): bool
{
    $marketByName = $this->fetchMarketplacePluginsByName();
    if ($marketByName === null) {
        return false;  // Allow if marketplace unavailable or OSS
    }
    
    $nameKey = strtolower($pluginName);
    if ($nameKey === '' || !$marketByName->has($nameKey)) {
        return false;  // Allow if not in marketplace (custom plugin)
    }
    
    $market = $marketByName->get($nameKey);
    if (!$this->isPaidPlugin($market)) {
        return false;  // Allow free plugins
    }
    
    $hasLicense = (bool) ($market['has_license'] ?? false);
    $isExpiredOverGrace = $this->isExpiredOverGraceWeek($market);
    
    return !$hasLicense || $isExpiredOverGrace;
}
```

**Usage in PluginController**:
```php
// Before enabling plugin
if ((new PluginLicenseSyncService())->shouldBlockActivation($plugin->plugin_name)) {
    return response()->json([
        'toastr' => [
            'type' => 'error',
            'message' => exmtrans('plugin.message.license_required')
        ]
    ]);
}
```

## Force Sync

### Trigger Manual Sync

```php
public function syncForced(int $minutesToThrottle = 1440): void
{
    $cacheKey = $this->getThrottleCacheKey();
    
    // Mark early to prevent stampede on concurrent requests
    Cache::put($cacheKey, now(), now()->addMinutes(max(1, $minutesToThrottle)));
    
    $this->sync();
}
```

**Use Cases**:
- After license purchase
- Admin manually triggers sync
- Initial system setup

## Database Schema Changes

### Plugin Model - options Field

```php
// Before sync (invalid license)
{
  "active_flg": 0,
  "options": {
    "disabled_by_license": true
  }
}

// After license renewed
{
  "active_flg": 1,
  "options": {
    // "disabled_by_license" removed
  }
}
```

**Field**: `plugins.options` (JSON)

**New Option**:
- `disabled_by_license` (bool): Indicates plugin was auto-disabled due to license

## Timeline Example

### Scenario: Plugin License Expires

```
Day 0 (Feb 10):
- License expires at 23:59:59
- Plugin still enabled
- No email sent

Day 1 (Feb 11):
- Sync detects: expired but in grace period
- Plugin still enabled
- Warning email sent to admins
- Cache key: warning_sent:pluginname|2026-02-11

Day 2 (Feb 12):
- Sync detects: expired but in grace period
- Plugin still enabled
- Warning email sent again (new cache key for today)

Day 7 (Feb 17):
- Last day of grace period
- Plugin still enabled
- Warning email sent

Day 8 (Feb 18):
- Sync detects: expired over grace (10 + 7 = 17 < 18)
- Plugin auto-disabled
- active_flg = 0
- options.disabled_by_license = true
- No email (already past grace)

Day 9 (Feb 19):
- User renews license on marketplace
- Next sync (within 24h):
  - Detects valid license
  - Plugin auto-enabled
  - active_flg = 1
  - disabled_by_license removed
```

## Error Handling

### Graceful Degradation

```php
// Middleware never throws
try {
    (new PluginLicenseSyncService())->syncThrottled(1440);
} catch (\Throwable $e) {
    Log::warning('[PluginLicenseSync] Failed: ' . $e->getMessage());
}
// Always continue to next middleware
```

### Marketplace Unavailable

```php
if (!$resp->ok()) {
    Log::info('[PluginLicenseSync] Marketplace license check skipped');
    return null;  // Skip sync, keep current state
}
```

**Behavior**: If marketplace is down, plugins keep current state (enabled/disabled)

### Invalid Response

```php
if (!is_array($marketPlugins)) {
    Log::warning('[PluginLicenseSync] Invalid data');
    return null;  // Skip sync
}
```

## Performance Considerations

### Throttling

- Default: 24 hours between syncs
- Prevents API spam
- Reduces server load

### Caching

```php
// Marketplace data cached in memory
protected $marketPluginsByName = null;

// Subsequent calls in same request use cached data
$data = $this->fetchMarketplacePluginsByName();  // API call
$data2 = $this->fetchMarketplacePluginsByName(); // Cached
```

### Minimize Database Writes

```php
// Only save if state actually changed
if (boolval($installedPlugin->active_flg) || !$disabledByLicense) {
    $installedPlugin->save();
    $changedCount++;
}
```

## Testing Scenarios

### 1. Free Plugin
```php
$market = ['is_free' => true, 'price' => 0];
// Should: Never check license, always enabled
```

### 2. Paid Plugin - Valid License
```php
$market = [
    'is_free' => false,
    'has_license' => true,
    'expires_at' => '2027-12-31'
];
// Should: Enabled, no email
```

### 3. Paid Plugin - Expired (Grace)
```php
$market = [
    'is_free' => false,
    'has_license' => true,
    'expires_at' => '2026-02-20'  // 4 days ago
];
// Should: Still enabled, daily warning email
```

### 4. Paid Plugin - Expired (Over Grace)
```php
$market = [
    'is_free' => false,
    'has_license' => true,
    'expires_at' => '2026-02-10'  // 14 days ago
];
// Should: Auto-disabled
```

### 5. Paid Plugin - No License
```php
$market = [
    'is_free' => false,
    'has_license' => false
];
// Should: Auto-disabled immediately
```
