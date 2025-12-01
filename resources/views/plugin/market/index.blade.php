<!-- Search Form -->
@if(session('successMess'))
<div class="alert alert-success alert-dismissible">
    <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
    <i class="icon fa fa-check"></i> {{ session('successMess') }}
</div>
@endif

@if(session('errorMess'))
<div class="alert alert-danger alert-dismissible">
    <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
    <i class="icon fa fa-ban"></i> {{ session('errorMess') }}
</div>
@endif

<div class="box">
    <div class="box-header with-border">
        <h3 class="box-title">{{ exmtrans('plugin.market.search.title') }}</h3>
    </div>
    <div class="box-body">
        <form action="{{ admin_url('plugin-market') }}" method="GET" id="plugin-search-form">
            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        <label>{{ exmtrans('plugin.market.search.keyword') }}</label>
                        <input type="text" name="keyword" class="form-control" 
                            placeholder="{{ exmtrans('plugin.market.search.keyword_placeholder') }}"
                            value="{{ request('keyword') }}">
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label>{{ exmtrans('plugin.market.search.type') }}</label>
                        <select name="type" class="form-control">
                            <option value="">{{ exmtrans('plugin.market.search.all_types') }}</option>
                            <option value="page" {{ request('type') == 'page' ? 'selected' : '' }}>Page</option>
                            <option value="dashboard" {{ request('type') == 'dashboard' ? 'selected' : '' }}>Dashboard</option>
                            <option value="trigger" {{ request('type') == 'trigger' ? 'selected' : '' }}>Trigger</option>
                            <option value="button" {{ request('type') == 'button' ? 'selected' : '' }}>Button</option>
                            <option value="api" {{ request('type') == 'api' ? 'selected' : '' }}>API</option>
                            <option value="view" {{ request('type') == 'view' ? 'selected' : '' }}>View</option>
                            <option value="batch" {{ request('type') == 'batch' ? 'selected' : '' }}>Batch</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label>{{ exmtrans('plugin.market.search.price') }}</label>
                        <select name="price" class="form-control">
                            <option value="">{{ exmtrans('plugin.market.search.all_prices') }}</option>
                            <option value="free" {{ request('price') == 'free' ? 'selected' : '' }}>{{ exmtrans('plugin.market.free') }}</option>
                            <option value="paid" {{ request('price') == 'paid' ? 'selected' : '' }}>{{ exmtrans('plugin.market.search.paid') }}</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label>{{ exmtrans('plugin.market.search.status') }}</label>
                        <select name="status" class="form-control">
                            <option value="">{{ exmtrans('plugin.market.search.all_status') }}</option>
                            <option value="active" {{ request('status') == 'active' ? 'selected' : '' }}>{{ exmtrans('plugin.market.search.active') }}</option>
                            <option value="inactive" {{ request('status') == 'inactive' ? 'selected' : '' }}>{{ exmtrans('plugin.market.search.inactive') }}</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fa fa-search"></i> {{ exmtrans('plugin.market.search.search') }}
                            </button>
                            <a href="{{ admin_url('plugin-market') }}" class="btn btn-default">
                                <i class="fa fa-refresh"></i> {{ exmtrans('plugin.market.search.reset') }}
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Results Summary -->
@if(request()->hasAny(['keyword', 'type', 'price', 'status']))
<div class="alert alert-info">
    <i class="fa fa-info-circle"></i> 
    {{ exmtrans('plugin.market.search.results_found', ['count' => count($plugins)]) }}
    @if(request('keyword'))
        - {{ exmtrans('plugin.market.search.keyword') }}: <strong>{{ request('keyword') }}</strong>
    @endif
</div>
@endif

<div class="box plugin-market-box">
    <div class="box-body p-0">
        <table class="table table-hover mb-0 plugin-market-table">
            <thead class="table-dark">
                <tr>
                    <th>{{ exmtrans('plugin.market.id') }}</th>
                    <th>{{ exmtrans('plugin.market.name') }}</th>
                    <th>{{ exmtrans('plugin.market.internal_name') }}</th>
                    <th>{{ exmtrans('plugin.market.type') }}</th>
                    <th>{{ exmtrans('plugin.market.author') }}</th>
                    <th>{{ exmtrans('plugin.market.latest_version') }}</th>
                    <th>{{ exmtrans('plugin.market.description_col') }}</th>
                    <th>{{ exmtrans('plugin.market.price') }}</th>
                    <th>{{ exmtrans('plugin.market.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($plugins as $plugin)
                    <tr>
                        <td>{{ $plugin['id'] ?? '—' }}</td>
                        <td><strong>{{ $plugin['plugin_name'] ?? $plugin['plugin_name'] }}</strong></td>
                        <td><code>{{ $plugin['plugin_name'] ?? '—' }}</code></td>
                        <td><span class="badge bg-secondary">{{ $plugin['plugin_types'] ?? '—' }}</span></td>
                        <td>{{ $plugin['user']['name'] ?? '—' }}</td>
                        <td><span class="badge bg-info">{{ $plugin['version'] ?? '—' }}</span></td>
                        <td><small>{{ $plugin['description'] ?? '—' }}</small></td>
                        <td>
                            @if(isset($plugin['price']) && $plugin['price'] > 0)
                                <span class="text-danger">${{ number_format($plugin['price'], 2) }}</span>
                            @else
                                <span class="text-success">{{ exmtrans('plugin.market.free') }}</span>
                            @endif
                        </td>
                        <td>
                            @php
                                $isActive = isset($plugin['check_status']) && strtolower($plugin['check_status']) === 'active';
                            @endphp

                            @if($plugin['is_installed'] ?? false)
                                @if($plugin['has_update'] ?? false)
                                    <!-- Plugin installed but update available -->
                                    @if(isset($plugin['price']) && $plugin['price'] > 0)
                                        <!-- Paid plugin update -->
                                        <button type="button" class="btn btn-warning btn-sm" 
                                            data-toggle="modal" 
                                            data-target="#licenseModal{{ $plugin['id'] }}"
                                            {{ !$isActive ? 'disabled' : '' }}>
                                            <i class="fa fa-arrow-up"></i> {{ exmtrans('plugin.market.update') }}
                                        </button>
                                    @else
                                        <!-- Free plugin update: show version selector modal -->
                                        <button type="button" class="btn btn-warning btn-sm" 
                                            data-toggle="modal" 
                                            data-target="#versionModal{{ $plugin['id'] }}"
                                            {{ !$isActive ? 'disabled' : '' }}>
                                            <i class="fa fa-arrow-up"></i> {{ exmtrans('plugin.market.update') }}
                                        </button>
                                    @endif
                                    <!-- Uninstall button for installed plugin with update -->
                                    <form action="{{ route('plugin.market.uninstall', $plugin['id']) }}" method="POST"
                                        style="display:inline;" 
                                        class="uninstall-form"
                                        data-plugin-id="{{ $plugin['id'] }}"
                                        data-plugin-name="{{ $plugin['plugin_name'] ?? 'Plugin' }}">
                                        @csrf
                                        <button type="submit" class="btn btn-danger btn-sm uninstall-btn">
                                            <i class="fa fa-trash"></i> {{ exmtrans('plugin.market.uninstall') }}
                                        </button>
                                    </form>
                                @else
                                    <!-- Plugin installed and up to date - still allow version selection -->
                                    <span class="badge badge-success mr-1">
                                        <i class="fa fa-check"></i> {{ exmtrans('plugin.market.installed', ['version' => $plugin['current_version']]) }}
                                    </span>
                                    @if(isset($plugin['price']) && $plugin['price'] > 0)
                                        <!-- Paid plugin: allow reinstall/downgrade -->
                                        <button type="button" class="btn btn-info btn-sm" 
                                            data-toggle="modal" 
                                            data-target="#licenseModal{{ $plugin['id'] }}"
                                            {{ !$isActive ? 'disabled' : '' }}>
                                            <i class="fa fa-refresh"></i> {{ exmtrans('plugin.market.install') }}
                                        </button>
                                    @else
                                        <!-- Free plugin: allow reinstall/downgrade -->
                                        <button type="button" class="btn btn-info btn-sm" 
                                            data-toggle="modal" 
                                            data-target="#versionModal{{ $plugin['id'] }}"
                                            {{ !$isActive ? 'disabled' : '' }}>
                                            <i class="fa fa-refresh"></i> {{ exmtrans('plugin.market.install') }}
                                        </button>
                                    @endif
                                    <!-- Uninstall button for installed plugin -->
                                    <form action="{{ route('plugin.market.uninstall', $plugin['id']) }}" method="POST"
                                        style="display:inline;" 
                                        class="uninstall-form"
                                        data-plugin-id="{{ $plugin['id'] }}"
                                        data-plugin-name="{{ $plugin['plugin_name'] ?? 'Plugin' }}">
                                        @csrf
                                        <button type="submit" class="btn btn-danger btn-sm uninstall-btn">
                                            <i class="fa fa-trash"></i> {{ exmtrans('plugin.market.uninstall') }}
                                        </button>
                                    </form>
                                @endif
                            @else
                                <!-- Plugin not installed -->
                                @if(isset($plugin['price']) && $plugin['price'] > 0)
                                    <!-- Paid plugin: show modal for license key -->
                                    <button type="button" class="btn btn-success btn-sm" 
                                        data-toggle="modal" 
                                        data-target="#licenseModal{{ $plugin['id'] }}"
                                        {{ !$isActive ? 'disabled' : '' }}>
                                        {{ exmtrans('plugin.market.install') }}
                                    </button>
                                @else
                                    <!-- Free plugin: show version selector modal -->
                                    <button type="button" class="btn btn-success btn-sm" 
                                        data-toggle="modal" 
                                        data-target="#versionModal{{ $plugin['id'] }}"
                                        {{ !$isActive ? 'disabled' : '' }}>
                                        {{ exmtrans('plugin.market.install') }}
                                    </button>
                                @endif
                            @endif
                            <a href="{{ route('plugin.market.show', $plugin['id']) }}" class="btn btn-primary btn-sm">{{ exmtrans('plugin.market.details') }}</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="text-center text-muted py-3">{{ exmtrans('plugin.market.plugin_not_found') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<!-- Version Selection Modals for free plugins -->
@foreach($plugins as $plugin)
    @if(!isset($plugin['price']) || $plugin['price'] == 0)
        <div class="modal fade" id="versionModal{{ $plugin['id'] }}" tabindex="-1" role="dialog">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ exmtrans('plugin.market.version_modal.title') }}</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <form action="{{ route('plugin.market.install', $plugin['id']) }}" method="POST" class="install-form-free">
                        @csrf
                        <div class="modal-body">
                            <p>{{ exmtrans('plugin.market.version_modal.plugin') }}: <strong>{{ $plugin['plugin_name'] ?? 'Unknown' }}</strong></p>
                            <div class="form-group">
                                <label for="version{{ $plugin['id'] }}">{{ exmtrans('plugin.market.version_modal.version') }} <span class="text-danger">*</span></label>
                                <select class="form-control version-select" 
                                    id="version{{ $plugin['id'] }}" 
                                    name="version"
                                    data-plugin-id="{{ $plugin['id'] }}"
                                    required>
                                    <option value="">{{ exmtrans('plugin.market.version_modal.select_version') }}</option>
                                </select>
                                <small class="form-text text-muted" id="changelog{{ $plugin['id'] }}"></small>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ exmtrans('plugin.market.version_modal.cancel') }}</button>
                            <button type="submit" class="btn btn-success install-free-btn">{{ exmtrans('plugin.market.version_modal.install') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
@endforeach

<!-- License Key Modals for paid plugins -->
@foreach($plugins as $plugin)
    @if(isset($plugin['price']) && $plugin['price'] > 0)
        <div class="modal fade" id="licenseModal{{ $plugin['id'] }}" tabindex="-1" role="dialog">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ exmtrans('plugin.market.license_modal.title') }}</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <form action="{{ route('plugin.market.install', $plugin['id']) }}" method="POST" class="install-form-paid">
                        @csrf
                        <div class="modal-body">
                            <p>{{ exmtrans('plugin.market.license_modal.plugin') }}: <strong>{{ $plugin['plugin_name'] ?? 'Unknown' }}</strong></p>
                            <p>{{ exmtrans('plugin.market.license_modal.price') }}: <span class="text-danger">${{ number_format($plugin['price'], 2) }}</span></p>
                            <div class="form-group">
                                <label for="license_key{{ $plugin['id'] }}">{{ exmtrans('plugin.market.license_modal.license_key') }}</label>
                                <input type="text" 
                                    class="form-control" 
                                    id="license_key{{ $plugin['id'] }}" 
                                    name="license_key" 
                                    placeholder="{{ exmtrans('plugin.market.license_modal.license_key_placeholder') }}">
                                <small class="form-text text-muted">{{ exmtrans('plugin.market.license_modal.license_key_help') }}</small>
                            </div>
                            <div class="form-group">
                                <label for="version_paid{{ $plugin['id'] }}">{{ exmtrans('plugin.market.license_modal.version') }} <span class="text-danger">*</span></label>
                                <select class="form-control version-select" 
                                    id="version_paid{{ $plugin['id'] }}" 
                                    name="version"
                                    data-plugin-id="{{ $plugin['id'] }}"
                                    required>
                                    <option value="">{{ exmtrans('plugin.market.license_modal.version') }}</option>
                                </select>
                                <small class="form-text text-muted" id="changelog_paid{{ $plugin['id'] }}"></small>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ exmtrans('plugin.market.license_modal.cancel') }}</button>
                            <button type="submit" class="btn btn-success install-paid-btn">{{ exmtrans('plugin.market.license_modal.install') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
@endforeach

<style>
.plugin-market-box {
    position: relative;
    border-radius: 3px;
    background: #ffffff;
    border-top: 3px solid #d2d6de;
    margin-bottom: 20px;
    width: 100%;
    box-shadow: 0 1px 1px rgba(0, 0, 0, 0.1);
}

.plugin-market-table {
    background-color: white;
}

.install-btn:disabled, .install-paid-btn:disabled {
    cursor: not-allowed;
    opacity: 0.6;
}

.spinner-border-sm {
    width: 1rem;
    height: 1rem;
    border-width: 0.15em;
}
</style>

<script>
// Wrap all initialization logic in a function
function initPluginMarket() {
    // Handle free plugin installation
    document.querySelectorAll('.install-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const btn = form.querySelector('.install-btn');
            const pluginName = form.dataset.pluginName;
            const formData = new FormData(form);
            
            // Disable button and show loading
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-2"></span>{{ exmtrans('plugin.market.message.installing') }}';
            
            fetch(form.action, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-CSRF-TOKEN': formData.get('_token')
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    btn.innerHTML = '<i class="fa fa-check"></i> {{ exmtrans('plugin.market.installed_short') }}';
                    btn.classList.remove('btn-success');
                    btn.classList.add('btn-secondary');
                    
                    // Show success message
                    if (typeof toastr !== 'undefined') {
                        toastr.success("{{ exmtrans('plugin.market.message.install_success', ['name' => '']) }}".replace(':name', pluginName));
                    } else {
                        alert("{{ exmtrans('plugin.market.message.install_success', ['name' => '']) }}".replace(':name', pluginName));
                    }
                    
                    // Reload page after 2 seconds
                    setTimeout(function() {
                        window.location.reload();
                    }, 2000);
                } else {
                    throw new Error(data.error || "{{ exmtrans('plugin.market.message.install_failed') }}");
                }
            })
            .catch(error => {
                console.error('Error:', error);
                btn.disabled = false;
                btn.innerHTML = "{{ exmtrans('plugin.market.install') }}";
                
                // Show error message
                const errorMsg = error.message || "{{ exmtrans('plugin.market.message.install_failed') }}";
                if (typeof toastr !== 'undefined') {
                    toastr.error(errorMsg);
                } else {
                    alert('Error: ' + errorMsg);
                }
            });
        });
    });
    
    // Handle paid plugin installation
    document.querySelectorAll('.install-form-paid').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const btn = form.querySelector('.install-paid-btn');
            const formData = new FormData(form);
            const licenseKey = form.querySelector('[name="license_key"]').value;
            const versionId = form.querySelector('[name="version"]').value;
            
            if (!versionId) {
                alert("{{ exmtrans('plugin.market.message.please_select_version') }}");
                return;
            }
            
            // Disable button and show loading
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-2"></span>{{ exmtrans('plugin.market.message.installing') }}';
            
            fetch(form.action, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-CSRF-TOKEN': formData.get('_token')
                }
            })
            .then(response => response.json())
            .then(data => {
                // Check if server wants to redirect to payment page
                if (data.redirect) {
                    // Before leaving the page, restore button state
                    btn.disabled = false;
                    btn.innerHTML = "{{ exmtrans('plugin.market.license_modal.install') }}";
                    window.location.href = data.redirect;
                    return;
                }
                
                if (data.success) {
                    // Close modal
                    const modal = form.closest('.modal');
                    if (modal) {
                        $(modal).modal('hide');
                    }
                    
                    // Show success message
                    swal({
                        title: "{{ exmtrans('common.success') }}",
                        text: data.message || "{{ exmtrans('plugin.market.message.install_success', ['name' => '']) }}",
                        type: "success",
                        timer: 2000,
                        showConfirmButton: false
                    });
                    
                    // Reload page after 2 seconds
                    setTimeout(function() {
                        window.location.reload();
                    }, 2000);
                } else {
                    throw new Error(data.error || 'Installation failed');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                btn.disabled = false;
                btn.innerHTML = "{{ exmtrans('plugin.market.license_modal.install') }}";
                
                // Show error message
                const errorMsg = error.message || "{{ exmtrans('plugin.market.message.install_failed') }}";
                swal({
                    title: "{{ exmtrans('common.error') }}",
                    text: errorMsg,
                    type: "error"
                });
            });
        });
    });
    
    // Handle plugin uninstallation using Exment.CommonEvent.ShowSwal
    // Same pattern as backup/index.blade.php - let Exment handle success/redirect automatically
    document.querySelectorAll('.uninstall-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            
            const form = btn.closest('.uninstall-form');
            const pluginName = form.dataset.pluginName;
            const pluginId = form.dataset.pluginId;
            
            // Replace :name with actual plugin name - use raw strings to avoid HTML encoding
            const confirmTitle = {!! json_encode(exmtrans('plugin.market.message.uninstall_confirm')) !!}.replace(':name', pluginName);
            
            const url = '{{ admin_url('plugin-market') }}/' + pluginId + '/uninstall';
            
            // Use Exment's standard ShowSwal pattern with redirect to reload current page
            Exment.CommonEvent.ShowSwal(url, {
                title: confirmTitle,
                text: {!! json_encode(exmtrans('plugin.market.message.uninstall_confirm_text')) !!},
                type: 'warning',
                method: 'POST',
                confirm: {!! json_encode(exmtrans('plugin.market.uninstall')) !!},
                cancel: {!! json_encode(exmtrans('plugin.market.version_modal.cancel')) !!},
                data: {},
                redirect: '{{ admin_url('plugin-market') }}'  // Reload plugin market page after success
            });
        });
    });

    // Load versions when modal is opened
    $('.modal[id^="versionModal"], .modal[id^="licenseModal"]').on('show.bs.modal', function(e) {
        const modal = $(this);
        const pluginId = modal.attr('id').match(/\d+/)[0];
        const versionSelect = modal.find('.version-select');
        
        console.log('Loading versions for plugin:', pluginId);
        
        // Load versions from API - use Laravel config
        const marketplaceUrl = '{{ rtrim(config("app.marketplace_url", env("MARKETPLACE_URL", "http://marketplace.local")), "/") }}';
        fetch(`${marketplaceUrl}/api/plugins/${pluginId}/versions`)
            .then(response => response.json())
            .then(data => {
                console.log('Versions data:', data);
                
                versionSelect.empty();
                versionSelect.append('<option value="">{{ exmtrans('plugin.market.version_modal.select_version') }}</option>');
                
                if (data.versions && data.versions.length > 0) {
                    data.versions.forEach(function(version) {
                        console.log('Version:', version.version, 'ID:', version.id, 'Price:', version.price, 'Download URL:', version.download_url);

                        // Build label including latest flag and price (Free / amount)
                        let label = version.version;
                        const isLatest = !!version.is_latest;
                        const price = Number(version.price || 0);

                        if (isLatest) {
                            label += ` ({{ exmtrans('plugin.market.version_modal.latest') }})`;
                        }

                        if (price > 0) {
                            label += ` - $${price.toFixed(2)}`;
                        } else {
                            label += ` - {{ exmtrans('plugin.market.free') }}`;
                        }
                        const option = $('<option></option>')
                            .attr('value', version.id)
                            .attr('data-changelog', version.changelog || '')
                            .attr('data-download-url', version.download_url || '')
                            .attr('data-price', price)
                            .text(label);
                        versionSelect.append(option);
                    });
                    
                    // Select latest version by default
                    const latestVersion = data.versions.find(v => v.is_latest);
                    if (latestVersion) {
                        versionSelect.val(latestVersion.id).trigger('change');
                    }
                } else {
                    versionSelect.append('<option value="">{{ exmtrans('plugin.market.message.no_versions') }}</option>');
                }
            })
            .catch(error => {
                console.error('Error loading versions:', error);
                versionSelect.empty();
                versionSelect.append('<option value="">{{ exmtrans('plugin.market.message.version_load_failed') }}</option>');
            });
    });
    
    // Show changelog when version is selected
    $('.version-select').on('change', function() {
        const selected = $(this).find('option:selected');
        const changelog = selected.attr('data-changelog');
        const pluginId = $(this).data('plugin-id');
        const changelogElement = $('#changelog' + pluginId + ', #changelog_paid' + pluginId);
        
        if (changelog) {
            changelogElement.text("{{ exmtrans('plugin.market.version_modal.changelog') }}: " + changelog);
        } else {
            changelogElement.text('');
        }
    });

    // Handle free plugin installation with version
    document.querySelectorAll('.install-form-free').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const btn = form.querySelector('.install-free-btn');
            const formData = new FormData(form);
            const versionId = form.querySelector('[name="version"]').value;
            
            if (!versionId) {
                alert("{{ exmtrans('plugin.market.message.please_select_version') }}");
                return;
            }
            
            // Disable button and show loading
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-2"></span>{{ exmtrans('plugin.market.message.installing') }}';
            
            fetch(form.action, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-CSRF-TOKEN': formData.get('_token')
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const modal = form.closest('.modal');
                    if (modal) {
                        $(modal).modal('hide');
                    }
                    
                    setTimeout(function() {
                        window.location.reload();
                    }, 2000);
                } else {
                    throw new Error(data.error || "{{ exmtrans('plugin.market.message.install_failed') }}");
                }
            })
            .catch(error => {
                console.error('Error:', error);
                btn.disabled = false;
                btn.innerHTML = "{{ exmtrans('plugin.market.version_modal.install') }}";
                
                const errorMsg = error.message || "{{ exmtrans('plugin.market.message.install_failed') }}";
                if (typeof toastr !== 'undefined') {
                    toastr.error(errorMsg);
                } else {
                    alert('Error: ' + errorMsg);
                }
            });
        });
    });

    // Auto-install plugin after payment callback
    @if(session('plugin_auto_install'))
    (function() {
        const autoInstallData = {!! json_encode(session('plugin_auto_install')) !!};
        console.log('[PluginMarket] Auto-installing plugin after payment:', autoInstallData);
        
        // Clear session data
        fetch('{{ admin_url("plugin-market/clear-auto-install") }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Content-Type': 'application/json'
            }
        });
        
        // Show loading message
        swal({
            title: "{{ exmtrans('plugin.market.message.installing') }}",
            text: autoInstallData.plugin_name,
            type: "info",
            showConfirmButton: false,
            allowOutsideClick: false
        });
        
        // Trigger install with license key
        const formData = new FormData();
        formData.append('_token', '{{ csrf_token() }}');
        formData.append('version', autoInstallData.version_id);
        formData.append('license_key', autoInstallData.license_key);
        
        fetch('{{ admin_url("plugin-market") }}/' + autoInstallData.plugin_id + '/install', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                swal({
                    title: "{{ exmtrans('common.success') }}",
                    text: data.message || "{{ exmtrans('plugin.market.message.install_success', ['name' => '']) }}",
                    type: "success",
                    timer: 2000,
                    showConfirmButton: false
                });
                
                setTimeout(function() {
                    window.location.reload();
                }, 2000);
            } else {
                throw new Error(data.error || "{{ exmtrans('plugin.market.message.install_failed') }}");
            }
        })
        .catch(error => {
            console.error('Error:', error);
            swal({
                title: "{{ exmtrans('common.error') }}",
                text: error.message || "{{ exmtrans('plugin.market.message.install_failed') }}",
                type: "error"
            });
        });
    })();
    @endif
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', initPluginMarket);

// Re-initialize after PJAX navigation (for Exment/Laravel-Admin)
$(document).on('pjax:end', function() {
    console.log('[PluginMarket] PJAX navigation detected, reinitializing...');
    // Wait a bit for DOM to be ready
    setTimeout(initPluginMarket, 100);
});

</script>