# Plugin Market - User Interface & Workflows

## Views Overview

### 1. Market Index View
**File**: `resources/views/plugin/market/index.blade.php` (951 dòng)

**URL**: `/admin/plugin-market`

Giao diện chính hiển thị danh sách plugins trong marketplace dạng grid layout.

### 2. Market Detail View
**File**: `resources/views/plugin/market/detail.blade.php` (194 dòng)

**URL**: `/admin/plugin-market/{id}`

Giao diện chi tiết plugin với thông tin đầy đủ, screenshots, versions, và action buttons.

## UI Components & Features

### Grid Layout

**Structure**:
```blade
<div class="plugin-market-grid">
    @foreach($plugins as $plugin)
    <div class="plugin-card">
        <!-- Plugin thumbnail -->
        <div class="plugin-thumbnail">
            <img src="{{ $plugin['icon'] ?? '/images/default-plugin.png' }}" alt="{{ $plugin['plugin_view_name'] }}">
        </div>
        
        <!-- Plugin info -->
        <div class="plugin-info">
            <h4 class="plugin-name">{{ $plugin['plugin_view_name'] }}</h4>
            <p class="plugin-author">by {{ $plugin['author'] }}</p>
            <p class="plugin-description">{{ Str::limit($plugin['description'], 100) }}</p>
            
            <!-- Version & license badges -->
            <div class="plugin-badges">
                @if($plugin['is_installed'])
                    <span class="badge badge-success">Installed</span>
                    @if($plugin['has_update'])
                        <span class="badge badge-warning">Update Available</span>
                    @endif
                @endif
                
                @if($plugin['is_free'])
                    <span class="badge badge-info">Free</span>
                @else
                    <span class="badge badge-primary">{{ $plugin['currency'] }} {{ $plugin['price'] }}</span>
                @endif
            </div>
        </div>
        
        <!-- Actions -->
        <div class="plugin-actions">
            <a href="{{ admin_url('plugin-market/' . $plugin['id']) }}" class="btn btn-sm btn-primary">
                View Details
            </a>
        </div>
    </div>
    @endforeach
</div>

<!-- Pagination -->
<div class="pagination-wrapper">
    {{ $plugins->links() }}
</div>
```

### Filter & Search Bar

```blade
<div class="plugin-market-filters">
    <form method="GET" action="{{ admin_url('plugin-market') }}">
        <!-- Search input -->
        <div class="form-group">
            <input type="text" 
                   name="search" 
                   class="form-control" 
                   placeholder="Search plugins..."
                   value="{{ request('search') }}">
        </div>
        
        <!-- Type filter -->
        <div class="form-group">
            <select name="type" class="form-control">
                <option value="">All Types</option>
                <option value="page" {{ request('type') == 'page' ? 'selected' : '' }}>Page</option>
                <option value="dashboard" {{ request('type') == 'dashboard' ? 'selected' : '' }}>Dashboard</option>
                <option value="trigger" {{ request('type') == 'trigger' ? 'selected' : '' }}>Trigger</option>
                <option value="api" {{ request('type') == 'api' ? 'selected' : '' }}>API</option>
                <option value="batch" {{ request('type') == 'batch' ? 'selected' : '' }}>Batch</option>
                <option value="validator" {{ request('type') == 'validator' ? 'selected' : '' }}>Validator</option>
                <option value="import" {{ request('type') == 'import' ? 'selected' : '' }}>Import</option>
                <option value="export" {{ request('type') == 'export' ? 'selected' : '' }}>Export</option>
            </select>
        </div>
        
        <!-- Status filter -->
        <div class="form-group">
            <select name="status" class="form-control">
                <option value="">All Status</option>
                <option value="approved" {{ request('status') == 'approved' ? 'selected' : '' }}>Approved</option>
                <option value="pending" {{ request('status') == 'pending' ? 'selected' : '' }}>Pending</option>
            </select>
        </div>
        
        <!-- Filter button -->
        <button type="submit" class="btn btn-primary">
            <i class="fa fa-filter"></i> Filter
        </button>
        
        <!-- Clear button -->
        <a href="{{ admin_url('plugin-market') }}" class="btn btn-default">
            <i class="fa fa-times"></i> Clear
        </a>
    </form>
</div>
```

### Plugin Detail Page

```blade
<div class="plugin-detail">
    <!-- Header section -->
    <div class="plugin-header">
        <div class="row">
            <div class="col-md-2">
                <img src="{{ $plugin['icon'] ?? '/images/default-plugin.png' }}" 
                     alt="{{ $plugin['plugin_view_name'] }}"
                     class="plugin-icon-large">
            </div>
            <div class="col-md-7">
                <h2>{{ $plugin['plugin_view_name'] }}</h2>
                <p class="plugin-author">by {{ $plugin['author'] }}</p>
                <div class="plugin-meta">
                    <span><i class="fa fa-download"></i> {{ $plugin['downloads'] ?? 0 }} downloads</span>
                    <span><i class="fa fa-star"></i> {{ $plugin['rating'] ?? 'N/A' }} ({{ $plugin['reviews_count'] ?? 0 }} reviews)</span>
                    <span><i class="fa fa-tag"></i> Version {{ $plugin['version'] }}</span>
                </div>
            </div>
            <div class="col-md-3">
                <!-- Price & action buttons -->
                @if($plugin['is_free'])
                    <div class="price-tag free">FREE</div>
                @else
                    <div class="price-tag">{{ $plugin['currency'] }} {{ number_format($plugin['price'], 2) }}</div>
                @endif
                
                <!-- Action buttons rendered by JavaScript -->
                <div id="plugin-actions" data-plugin-id="{{ $plugin['id'] }}">
                    <!-- Dynamic buttons based on install state -->
                </div>
            </div>
        </div>
    </div>
    
    <!-- Screenshots -->
    @if(!empty($plugin['screenshots']))
    <div class="plugin-screenshots">
        <h3>Screenshots</h3>
        <div class="screenshots-carousel">
            @foreach($plugin['screenshots'] as $screenshot)
            <div class="screenshot-item">
                <img src="{{ $screenshot }}" alt="Screenshot" class="img-responsive">
            </div>
            @endforeach
        </div>
    </div>
    @endif
    
    <!-- Description -->
    <div class="plugin-description">
        <h3>Description</h3>
        <div class="markdown-content">
            {!! Markdown::parse($plugin['long_description'] ?? $plugin['description'] ?? '') !!}
        </div>
    </div>
    
    <!-- Version selector & changelog -->
    <div class="plugin-versions">
        <h3>Versions</h3>
        <div class="version-selector">
            <select id="version-select" class="form-control">
                @foreach($plugin['versions'] ?? [] as $version)
                <option value="{{ $version['id'] }}" 
                        {{ $version['is_latest'] ? 'selected' : '' }}
                        data-version="{{ $version['version'] }}"
                        data-changelog="{{ $version['changelog'] ?? '' }}">
                    {{ $version['version'] }} 
                    @if($version['is_latest']) (Latest) @endif
                    - {{ Carbon\Carbon::parse($version['release_date'])->format('Y-m-d') }}
                </option>
                @endforeach
            </select>
        </div>
        
        <div id="version-changelog" class="changelog-content">
            <!-- Changelog displayed via JavaScript -->
        </div>
    </div>
    
    <!-- Requirements -->
    <div class="plugin-requirements">
        <h3>Requirements</h3>
        <ul>
            @if(!empty($plugin['minimum_exment_version']))
            <li>Exment version: >= {{ $plugin['minimum_exment_version'] }}</li>
            @endif
            @if(!empty($plugin['php_version']))
            <li>PHP version: {{ $plugin['php_version'] }}</li>
            @endif
        </ul>
    </div>
    
    <!-- Additional info -->
    <div class="plugin-links">
        @if(!empty($plugin['url']))
        <a href="{{ $plugin['url'] }}" target="_blank" class="btn btn-link">
            <i class="fa fa-home"></i> Homepage
        </a>
        @endif
        @if(!empty($plugin['documentation_url']))
        <a href="{{ $plugin['documentation_url'] }}" target="_blank" class="btn btn-link">
            <i class="fa fa-book"></i> Documentation
        </a>
        @endif
    </div>
</div>
```

## JavaScript Interactions

### Install Plugin

```javascript
$(document).ready(function() {
    // Install button click handler
    $(document).on('click', '.btn-install-plugin', function(e) {
        e.preventDefault();
        
        var pluginId = $(this).data('plugin-id');
        var versionId = $('#version-select').val();
        var pluginName = $(this).data('plugin-name');
        
        if (!versionId) {
            toastr.error('Please select a version');
            return;
        }
        
        Swal.fire({
            title: 'Install Plugin',
            text: 'Are you sure you want to install ' + pluginName + '?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, install it!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                installPlugin(pluginId, versionId);
            }
        });
    });
    
    function installPlugin(pluginId, versionId) {
        // Show loading state
        Swal.fire({
            title: 'Installing...',
            text: 'Please wait while the plugin is being installed',
            icon: 'info',
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
        
        // Make AJAX request
        $.ajax({
            url: admin_url('plugin-market/' + pluginId + '/install'),
            type: 'POST',
            data: {
                version: versionId,
                _token: LA.token
            },
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        title: 'Success!',
                        text: response.message,
                        icon: 'success',
                        confirmButtonText: 'OK'
                    }).then(() => {
                        // Reload page to update UI
                        location.reload();
                    });
                } else {
                    Swal.fire({
                        title: 'Error',
                        text: response.error || 'Installation failed',
                        icon: 'error',
                        confirmButtonText: 'OK'
                    });
                }
            },
            error: function(xhr) {
                var errorMsg = 'Installation failed';
                if (xhr.responseJSON && xhr.responseJSON.error) {
                    errorMsg = xhr.responseJSON.error;
                }
                
                Swal.fire({
                    title: 'Error',
                    text: errorMsg,
                    icon: 'error',
                    confirmButtonText: 'OK'
                });
            }
        });
    }
});
```

### Update Plugin

```javascript
$(document).on('click', '.btn-update-plugin', function(e) {
    e.preventDefault();
    
    var pluginId = $(this).data('plugin-id');
    var versionId = $('#version-select').val();
    var currentVersion = $(this).data('current-version');
    var newVersion = $('#version-select option:selected').data('version');
    
    Swal.fire({
        title: 'Update Plugin',
        html: 'Update from version <strong>' + currentVersion + '</strong> to <strong>' + newVersion + '</strong>?<br><br>' +
              '<small class="text-warning">⚠️ The current version will be removed before installing the new version.</small>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, update it!',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33'
    }).then((result) => {
        if (result.isConfirmed) {
            updatePlugin(pluginId, versionId);
        }
    });
});

function updatePlugin(pluginId, versionId) {
    Swal.fire({
        title: 'Updating...',
        text: 'Please wait while the plugin is being updated',
        icon: 'info',
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    $.ajax({
        url: admin_url('plugin-market/' + pluginId + '/update'),
        type: 'POST',
        data: {
            version: versionId,
            _token: LA.token
        },
        success: function(response) {
            if (response.success) {
                Swal.fire({
                    title: 'Success!',
                    text: response.message,
                    icon: 'success',
                    confirmButtonText: 'OK'
                }).then(() => {
                    location.reload();
                });
            } else {
                Swal.fire({
                    title: 'Error',
                    text: response.error || 'Update failed',
                    icon: 'error',
                    confirmButtonText: 'OK'
                });
            }
        },
        error: function(xhr) {
            var errorMsg = 'Update failed';
            if (xhr.responseJSON && xhr.responseJSON.error) {
                errorMsg = xhr.responseJSON.error;
            }
            
            Swal.fire({
                title: 'Error',
                text: errorMsg,
                icon: 'error',
                confirmButtonText: 'OK'
            });
        }
    });
}
```

### Uninstall Plugin

```javascript
$(document).on('click', '.btn-uninstall-plugin', function(e) {
    e.preventDefault();
    
    var pluginId = $(this).data('plugin-id');
    var pluginName = $(this).data('plugin-name');
    
    Swal.fire({
        title: 'Uninstall Plugin',
        html: 'Are you sure you want to uninstall <strong>' + pluginName + '</strong>?<br><br>' +
              '<small class="text-danger">⚠️ This action cannot be undone. All plugin data and configuration will be removed.</small>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, uninstall it!',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6'
    }).then((result) => {
        if (result.isConfirmed) {
            uninstallPlugin(pluginId);
        }
    });
});

function uninstallPlugin(pluginId) {
    Swal.fire({
        title: 'Uninstalling...',
        text: 'Please wait',
        icon: 'info',
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    $.ajax({
        url: admin_url('plugin-market/' + pluginId + '/uninstall'),
        type: 'POST',
        data: {
            _token: LA.token
        },
        success: function(response) {
            if (response.result && response.swal) {
                Swal.fire({
                    title: 'Success!',
                    text: response.swal,
                    icon: 'success',
                    confirmButtonText: 'OK'
                }).then(() => {
                    location.reload();
                });
            } else {
                Swal.fire({
                    title: 'Error',
                    text: response.error || 'Uninstall failed',
                    icon: 'error',
                    confirmButtonText: 'OK'
                });
            }
        },
        error: function(xhr) {
            var errorMsg = 'Uninstall failed';
            if (xhr.responseJSON && xhr.responseJSON.error) {
                errorMsg = xhr.responseJSON.error;
            }
            
            Swal.fire({
                title: 'Error',
                text: errorMsg,
                icon: 'error',
                confirmButtonText: 'OK'
            });
        }
    });
}
```

### Dynamic Button Rendering

```javascript
function renderPluginActions(pluginId, isInstalled, hasUpdate, isFree, hasLicense, currentVersion) {
    var $container = $('#plugin-actions');
    $container.empty();
    
    if (!isInstalled) {
        // Not installed - show install button
        if (isFree || hasLicense) {
            $container.append(
                '<button class="btn btn-success btn-block btn-install-plugin" ' +
                'data-plugin-id="' + pluginId + '" ' +
                'data-plugin-name="' + pluginName + '">' +
                '<i class="fa fa-download"></i> Install' +
                '</button>'
            );
        } else {
            // Paid plugin without license
            $container.append(
                '<a href="' + purchaseUrl + '" class="btn btn-primary btn-block" target="_blank">' +
                '<i class="fa fa-shopping-cart"></i> Purchase License' +
                '</a>'
            );
        }
    } else {
        // Installed - show current version
        $container.append(
            '<div class="alert alert-success">' +
            '<i class="fa fa-check"></i> Installed (v' + currentVersion + ')' +
            '</div>'
        );
        
        if (hasUpdate) {
            // Update available
            $container.append(
                '<button class="btn btn-warning btn-block btn-update-plugin" ' +
                'data-plugin-id="' + pluginId + '" ' +
                'data-current-version="' + currentVersion + '">' +
                '<i class="fa fa-refresh"></i> Update Available' +
                '</button>'
            );
        }
        
        // Uninstall button
        $container.append(
            '<button class="btn btn-danger btn-block btn-uninstall-plugin" ' +
            'data-plugin-id="' + pluginId + '" ' +
            'data-plugin-name="' + pluginName + '">' +
            '<i class="fa fa-trash"></i> Uninstall' +
            '</button>'
        );
    }
}
```

### Version Selector Change Handler

```javascript
$('#version-select').on('change', function() {
    var selectedOption = $(this).find('option:selected');
    var version = selectedOption.data('version');
    var changelog = selectedOption.data('changelog');
    
    // Update changelog display
    $('#version-changelog').html(marked.parse(changelog || 'No changelog available'));
    
    // Update action buttons if needed
    // renderPluginActions(...);
});
```

## User Workflows

### Workflow 1: Browse & Install Free Plugin

```
1. User clicks "Plugin Market" in menu
   ↓
2. System displays plugin grid (index page)
   - Shows all plugins from marketplace
   - Filter by type, status
   - Search by keyword
   ↓
3. User finds free plugin "BasicExporter"
   - Badge shows "Free"
   - Badge shows "Not Installed"
   ↓
4. User clicks "View Details"
   ↓
5. System displays plugin detail page
   - Description, screenshots
   - Version selector (default: latest)
   - "Install" button (green)
   ↓
6. User clicks "Install"
   ↓
7. Browser shows confirmation dialog
   "Are you sure you want to install BasicExporter?"
   ↓
8. User clicks "Yes, install it!"
   ↓
9. JavaScript sends POST to /admin/plugin-market/123/install
   - Shows loading spinner
   ↓
10. Server:
    a. Fetches plugin info from marketplace
    b. Downloads ZIP file
    c. Extracts to plugins folder
    d. Registers in database
    ↓
11. Server responds: {"success": true, "message": "..."}
    ↓
12. Browser shows success message
    ↓
13. Page reloads
    ↓
14. Detail page now shows:
    - "Installed (v1.0.0)" badge
    - "Uninstall" button (red)
```

### Workflow 2: Update Existing Plugin

```
1. User navigates to Plugin Market
   ↓
2. System enriches marketplace data with local install info
   - Compares versions: marketplace vs installed
   - Sets has_update flag
   ↓
3. Plugin card shows "Update Available" badge
   ↓
4. User clicks "View Details"
   ↓
5. Detail page shows:
   - "Installed (v1.5.0)" alert
   - "Update Available" button (yellow)
   - Version selector shows v2.0.0 (latest)
   ↓
6. User selects version v2.0.0
   ↓
7. Changelog displays below selector
   ↓
8. User clicks "Update Available" button
   ↓
9. Confirmation dialog:
   "Update from version 1.5.0 to 2.0.0?"
   "⚠️ The current version will be removed..."
   ↓
10. User confirms
    ↓
11. Server:
    a. Deletes old version folder
    b. Removes database record
    c. Downloads new version ZIP
    d. Installs new version
    ↓
12. Success message: "Plugin updated successfully"
    ↓
13. Page reloads showing v2.0.0
```

### Workflow 3: Install Paid Plugin (With License)

```
1. User browses marketplace
   ↓
2. Finds paid plugin "AdvancedDataGrid"
   - Badge shows "USD 99.00"
   ↓
3. User clicks "View Details"
   ↓
4. System checks license via marketplace API
   - tenant_uuid sent in request
   - Response includes: has_license: true
   ↓
5. Detail page shows "Install" button
   (because has_license = true)
   ↓
6. User installs plugin (same as free plugin flow)
   ↓
7. Plugin works normally
   ↓
8. Background: PluginLicenseSync middleware runs every 24h
   - Validates license still active
   - Auto-disables if expired > 7 days
```

### Workflow 4: Install Paid Plugin (No License)

```
1. User browses marketplace
   ↓
2. Finds paid plugin "AdvancedDataGrid"
   - Badge shows "USD 99.00"
   ↓
3. User clicks "View Details"
   ↓
4. System checks license via marketplace API
   - Response includes: has_license: false
   ↓
5. Detail page shows "Purchase License" button
   (instead of Install button)
   ↓
6. User clicks "Purchase License"
   ↓
7. Browser opens marketplace in new tab
   - URL: https://marketplace.exment.org/plugins/123/purchase
   ↓
8. User completes purchase on marketplace
   ↓
9. User returns to Exment
   ↓
10. User refreshes detail page
    ↓
11. System re-checks license
    - Now has_license: true
    ↓
12. "Install" button now available
    ↓
13. User installs plugin
```

### Workflow 5: License Expiry Handling

```
Day -7: License valid
- Plugin enabled and working
- No warnings

Day 0: License expires (2026-02-24 23:59:59)
- Plugin still enabled (grace period)
- No immediate action

Day 1: First day after expiry
- PluginLicenseSync runs (middleware)
- Detects: expired but < 7 days
- Plugin remains enabled
- Sends warning email to admins:
  "Plugin license will expire in 7 days"

Day 2-7: Grace period
- Daily warning emails sent
- Plugin still functioning
- Users can renew license

Day 8: Grace period over (2026-03-03)
- PluginLicenseSync runs
- Detects: expired > 7 days
- Auto-disables plugin:
  * active_flg = 0
  * options.disabled_by_license = true
- No more warning emails
- Plugin stops working

Day 9: User renews license
- Purchases new license on marketplace
- Next sync (within 24h):
  * Detects has_license: true
  * Auto-enables plugin:
    - active_flg = 1
    - Removes disabled_by_license flag
- Plugin starts working again

If user tries to manually enable during grace period over:
- PluginController checks shouldBlockActivation()
- Returns error: "License required"
- Shows purchase URL
```

## Translation Keys

### English (resources/lang/en/exment.php)

```php
'plugin' => [
    'market' => [
        'title' => 'Plugin Market',
        'description' => 'Browse and install plugins from marketplace',
        'detail' => [
            'title' => 'Plugin Details',
        ],
        'message' => [
            'connection_error' => 'Failed to connect to marketplace. Please try again later.',
            'plugin_not_found' => 'Plugin not found in marketplace.',
            'please_select_version' => 'Please select a version to install.',
            'version_load_failed' => 'Failed to load plugin versions.',
            'version_not_found' => 'Selected version not found.',
            'no_download_url' => 'Download URL not available for this version.',
            'download_failed' => 'Failed to download plugin file.',
            'install_success' => 'Plugin :name installed successfully.',
            'update_success' => 'Plugin updated successfully.',
            'install_failed' => 'Plugin installation failed.',
            'install_error' => 'An error occurred during installation.',
            'invalid_plugin_data' => 'Invalid plugin data received from marketplace.',
            'plugin_not_installed' => 'Plugin is not installed.',
            'uninstall_success' => 'Plugin :name has been uninstalled successfully.',
            'uninstall_error' => 'An error occurred during uninstallation.',
        ],
    ],
    'message' => [
        'license_required' => 'This plugin requires a valid license. Please purchase a license to use this plugin.',
        'license_expired' => 'Your license for this plugin has expired. Please renew to continue using it.',
        'license_expired_warning_subject' => 'Plugin License Expiring Soon: :plugin',
        'license_expired_warning_body' => 'Your license for plugin ":plugin" has expired on :expires_at. The plugin will be automatically disabled on :disable_at (:days_left days remaining). Please renew your license at: :market_url',
    ],
],
```

### Japanese (resources/lang/ja/exment.php)

```php
'plugin' => [
    'market' => [
        'title' => 'プラグインマーケット',
        'description' => 'マーケットプレイスからプラグインを閲覧してインストール',
        'detail' => [
            'title' => 'プラグイン詳細',
        ],
        'message' => [
            'connection_error' => 'マーケットプレイスへの接続に失敗しました。後でもう一度お試しください。',
            'plugin_not_found' => 'プラグインが見つかりませんでした。',
            'please_select_version' => 'インストールするバージョンを選択してください。',
            'version_load_failed' => 'プラグインバージョンの読み込みに失敗しました。',
            'version_not_found' => '選択されたバージョンが見つかりませんでした。',
            'no_download_url' => 'このバージョンのダウンロードURLが利用できません。',
            'download_failed' => 'プラグインファイルのダウンロードに失敗しました。',
            'install_success' => 'プラグイン「:name」を正常にインストールしました。',
            'update_success' => 'プラグインを正常に更新しました。',
            'install_failed' => 'プラグインのインストールに失敗しました。',
            'install_error' => 'インストール中にエラーが発生しました。',
            'invalid_plugin_data' => 'マーケットプレイスから無効なプラグインデータを受信しました。',
            'plugin_not_installed' => 'プラグインがインストールされていません。',
            'uninstall_success' => 'プラグイン「:name」を正常にアンインストールしました。',
            'uninstall_error' => 'アンインストール中にエラーが発生しました。',
        ],
    ],
    'message' => [
        'license_required' => 'このプラグインには有効なライセンスが必要です。このプラグインを使用するにはライセンスを購入してください。',
        'license_expired' => 'このプラグインのライセンスが期限切れです。引き続き使用するには更新してください。',
        'license_expired_warning_subject' => 'プラグインライセンス期限切れ間近: :plugin',
        'license_expired_warning_body' => 'プラグイン「:plugin」のライセンスが:expires_atに期限切れになりました。:disable_at（残り:days_left日）にプラグインは自動的に無効化されます。以下のURLでライセンスを更新してください: :market_url',
    ],
],
```

## CSS Styling

### Plugin Card Styles

```css
.plugin-market-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 20px;
    padding: 20px 0;
}

.plugin-card {
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 15px;
    background: #fff;
    transition: box-shadow 0.3s;
}

.plugin-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.plugin-thumbnail {
    width: 100%;
    height: 150px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 15px;
    background: #f5f5f5;
    border-radius: 4px;
}

.plugin-thumbnail img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

.plugin-info h4 {
    margin: 0 0 5px 0;
    font-size: 16px;
    font-weight: 600;
}

.plugin-author {
    color: #666;
    font-size: 12px;
    margin: 0 0 10px 0;
}

.plugin-description {
    font-size: 13px;
    color: #333;
    margin-bottom: 15px;
    line-height: 1.4;
}

.plugin-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    margin-bottom: 15px;
}

.plugin-actions {
    display: flex;
    gap: 10px;
}

.plugin-actions .btn {
    flex: 1;
}
```

### Responsive Design

```css
@media (max-width: 768px) {
    .plugin-market-grid {
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 15px;
    }
    
    .plugin-market-filters form {
        flex-direction: column;
    }
    
    .plugin-market-filters .form-group {
        width: 100%;
        margin-bottom: 10px;
    }
}

@media (max-width: 480px) {
    .plugin-market-grid {
        grid-template-columns: 1fr;
    }
}
```

## Accessibility Features

### Keyboard Navigation

```blade
<!-- All interactive elements have proper tabindex -->
<button class="btn btn-install-plugin" 
        tabindex="0"
        aria-label="Install {{ $plugin['plugin_view_name'] }}">
    Install
</button>
```

### Screen Reader Support

```blade
<div class="plugin-card" role="article" aria-labelledby="plugin-name-{{ $plugin['id'] }}">
    <h4 id="plugin-name-{{ $plugin['id'] }}">{{ $plugin['plugin_view_name'] }}</h4>
    <!-- ... -->
</div>
```

### Loading States

```javascript
// Proper ARIA attributes for loading states
Swal.fire({
    title: 'Installing...',
    didOpen: () => {
        Swal.getPopup().setAttribute('aria-busy', 'true');
        Swal.showLoading();
    }
});
```

## Mobile Optimization

### Touch-Friendly Buttons

```css
.btn {
    min-height: 44px;  /* iOS recommendation */
    padding: 10px 20px;
}

.plugin-card {
    -webkit-tap-highlight-color: rgba(0,0,0,0.1);
}
```

### Swipe Carousel for Screenshots

```javascript
$('.screenshots-carousel').slick({
    dots: true,
    arrows: true,
    infinite: true,
    speed: 300,
    slidesToShow: 1,
    adaptiveHeight: true,
    swipe: true,
    swipeToSlide: true
});
```

## Performance Optimizations

### Lazy Loading Images

```blade
<img src="{{ $plugin['icon'] }}" 
     loading="lazy"
     alt="{{ $plugin['plugin_view_name'] }}">
```

### Pagination

```blade
<!-- Server-side pagination to limit DOM size -->
{{ $plugins->appends(request()->except('page'))->links() }}
```

### Debounced Search

```javascript
var searchTimeout;
$('#search-input').on('keyup', function() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(function() {
        $('#search-form').submit();
    }, 500);
});
```
