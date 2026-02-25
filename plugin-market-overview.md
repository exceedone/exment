# Plugin Market System - Tổng quan

## Commit Information
- **Commit Hash**: `3512ea0c992b5421864db697083a89a67a789755`
- **Author**: vohoangnhat <vohoangnhat@gmail.com>
- **Date**: Tue Feb 24 14:53:37 2026 +0700
- **Message**: plugin manager
- **Branch**: feature/before-plugin

## Mô tả chức năng

Commit này triển khai hệ thống **Plugin Market** hoàn chỉnh cho Exment - một marketplace tập trung cho phép người dùng duyệt, tìm kiếm, cài đặt, cập nhật và quản lý các plugin từ kho trung tâm.

## Các thành phần chính

### 1. Controllers
- **PluginMarketController** (795 dòng mới): Controller chính xử lý toàn bộ logic marketplace
  - Browse/search plugins từ marketplace
  - Xem chi tiết plugin
  - Cài đặt plugin từ marketplace
  - Cập nhật plugin đã cài
  - Gỡ cài đặt plugin
  - Quản lý auto-install session

- **PluginController** (cập nhật): Thêm 80 dòng, tích hợp với marketplace

### 2. Services
- **PluginLicenseSyncService** (351 dòng): Đồng bộ và quản lý license cho plugin có phí
  - Kiểm tra license validity
  - Auto-disable plugin khi license hết hạn
  - Gửi cảnh báo expiry qua email
  - Throttled sync để tránh overload
  
- **PluginRepository** (80 dòng): Tương tác với Plugin Marketplace API
  - Fetch danh sách plugin và versions
  - Cache kết quả để tối ưu performance

### 3. Middleware
- **PluginLicenseSync** (32 dòng): Middleware tự động sync license mọi request
  - Chạy sau authentication
  - Throttled để tránh spam API
  - Silent failure không ảnh hưởng user experience

### 4. Views
- **plugin/market/index.blade.php** (951 dòng): Giao diện danh sách plugin marketplace
- **plugin/market/detail.blade.php** (194 dòng): Giao diện chi tiết plugin

### 5. Configuration & Routes
- **config/exment.php**: Thêm 22 dòng config cho marketplace
- **RouteServiceProvider**: Đăng ký 8 routes mới

### 6. Translations
- **resources/lang/en/exment.php**: 138+ dòng translation
- **resources/lang/ja/exment.php**: 154+ dòng translation

## Thống kê thay đổi

```
13 files changed
2,792 insertions (+)
18 deletions (-)
```

## Kiến trúc hệ thống

```
┌─────────────────────────────────────────────────────────────┐
│                    User Interface (Blade)                    │
│  - Browse plugins grid with filters                         │
│  - Plugin detail page with version selector                 │
│  - Install/Update/Uninstall actions                         │
└───────────────────────┬─────────────────────────────────────┘
                        │
┌───────────────────────▼─────────────────────────────────────┐
│              PluginMarketController                          │
│  - index(): List plugins with filters                       │
│  - show($id): Plugin details                                │
│  - install($id): Download & install plugin                  │
│  - update($id): Update installed plugin                     │
│  - uninstall($id): Remove plugin                            │
└───────────────────────┬─────────────────────────────────────┘
                        │
        ┌───────────────┴────────────────┐
        │                                │
┌───────▼──────────┐          ┌─────────▼────────────┐
│ PluginRepository │          │PluginLicenseSyncService│
│ - fetchVersions()│          │ - syncThrottled()     │
│ - HTTP API calls │          │ - sync()              │
└───────┬──────────┘          │ - shouldBlockActivation()│
        │                     └─────────┬────────────┘
        │                               │
┌───────▼─────────────────────────────▼──────────┐
│      External Plugin Marketplace API            │
│  - GET /api/plugins (list)                     │
│  - GET /api/plugins/{id} (detail)              │
│  - GET /api/plugins/{id}/versions              │
│  - GET /api/plugins/{id}/versions/{vId}/download│
└─────────────────────────────────────────────────┘
```

## Tính năng nổi bật

### 1. **Seamless Integration**
- Tích hợp trực tiếp vào admin panel của Exment
- Sử dụng chung authentication và authorization
- UI/UX nhất quán với phần còn lại của hệ thống

### 2. **Smart Version Management**
- So sánh version tự động giữa installed vs marketplace
- Hiển thị trạng thái: not installed / installed / update available
- Version selector khi install/update

### 3. **License Management** (cho plugin có phí)
- Tự động kiểm tra license validity
- Grace period 7 ngày sau khi license expire
- Email warning tự động gửi cho system admins
- Auto-disable plugin khi license invalid

### 4. **Tenant-aware**
- Hỗ trợ multi-tenant với `tenant_uuid`
- Marketplace API trả về plugins dựa trên tenant permissions
- License check theo từng tenant

### 5. **Robust Error Handling**
- Connection timeout và retry logic
- Graceful degradation khi API unavailable
- User-friendly error messages
- Comprehensive logging

### 6. **Performance Optimization**
- Throttled license sync (mặc định 24h)
- Response caching (5 phút cho version list)
- Lazy loading và pagination cho danh sách plugin
- Warning email throttling (1 lần/ngày per plugin)

## Configuration Variables

Các config key mới trong `config/exment.php`:

```php
'market_plugin_url'                  // Marketplace base URL
'market_tenant_uuid'                 // Tenant UUID cho license check
'market_resign_signed_download_url'  // Re-sign S3 URLs (optional)
```

## API Endpoints

Routes mới được đăng ký:

```
GET  /admin/plugin-market                           - Browse marketplace
GET  /admin/plugin-market/{id}                      - Plugin detail
POST /admin/plugin-market/{id}/install              - Install plugin
POST /admin/plugin-market/{id}/update               - Update plugin  
POST /admin/plugin-market/{id}/uninstall            - Uninstall plugin
POST /admin/plugin-market/checkout/purchase         - Purchase flow
```

## Dependencies

### External APIs
- Plugin Marketplace HTTP API (configurabile via `market_plugin_url`)
- Default: `https://exment.org/api/plugins`

### Laravel Components
- `Illuminate\Support\Facades\Http` - HTTP client
- `Illuminate\Support\Facades\Cache` - Caching
- `Illuminate\Support\Facades\Mail` - Email notifications
- `Illuminate\Support\Facades\Storage` - File handling
- `Illuminate\Pagination\LengthAwarePaginator` - Pagination

## Security Considerations

1. **SSL Verification**: Sử dụng `withoutVerifying()` - cần review trong production
2. **Download Validation**: ZIP file được download nhưng validation chưa rõ ràng
3. **Signed URLs**: Hỗ trợ resign S3 signed URLs nếu cần
4. **Tenant Isolation**: Tenant UUID đảm bảo separation giữa các tenant

## Use Cases

### UC1: Browse và Install Plugin
1. User vào `/admin/plugin-market`
2. Duyệt/tìm kiếm plugin theo type, status
3. Click vào plugin để xem detail
4. Chọn version và click Install
5. System download ZIP, extract và install
6. Redirect về plugin list với success message

### UC2: Update Plugin
1. System tự động detect update available
2. User click Update button
3. System download version mới
4. Xóa version cũ
5. Install version mới
6. Success message

### UC3: License Expiry Handling
1. PluginLicenseSync middleware chạy mỗi request
2. Check license từ marketplace API
3. Nếu expired > 7 ngày: auto-disable plugin
4. Nếu expired <= 7 ngày: gửi warning email
5. Admin renew license trên marketplace
6. Sync auto-enable lại plugin

## Testing Considerations

Khi test chức năng này, cần kiểm tra:

1. **Network failures**: Marketplace API unavailable
2. **Invalid responses**: Malformed JSON, unexpected fields
3. **Download failures**: Corrupt ZIP, incomplete download
4. **License edge cases**: Expired exact, grace period boundary
5. **Concurrent requests**: Multiple users installing simultaneously
6. **Tenant isolation**: Tenant A không thấy plugin của Tenant B

## Migration & Rollback

### Migration
- Không có database migration trong commit này
- Chỉ thêm code và config
- Safe để deploy vì backward compatible

### Rollback
- Xóa routes trong RouteServiceProvider
- Remove middleware registration
- Xóa config keys
- Không ảnh hưởng data vì không có DB changes

## Future Enhancements

Các cải tiến có thể thêm vào sau:

1. Plugin rating và review system
2. Plugin preview/demo trước khi install
3. Bulk install/update operations
4. Plugin dependency management
5. Rollback to previous version
6. Backup trước khi update
7. Plugin usage analytics
8. Purchase flow integration
