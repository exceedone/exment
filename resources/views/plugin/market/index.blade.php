<div class="box plugin-market-box">
    <div class="box-body p-0">
        <table class="table table-hover mb-0 plugin-market-table">
            <thead class="table-dark">
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Internal Name</th>
                    <th>Type</th>
                    <th>Author</th>
                    <th>Latest Version</th>
                    <th>Description</th>
                    <th>Price</th>
                    <th>Actions</th>
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
                                <span class="text-success">Free</span>
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
                                            <i class="fa fa-arrow-up"></i> Update to {{ $plugin['version'] }}
                                        </button>
                                    @else
                                        <!-- Free plugin update -->
                                        <form action="{{ route('plugin.market.install', $plugin['id']) }}" method="POST"
                                            style="display:inline;" 
                                            class="install-form"
                                            data-plugin-id="{{ $plugin['id'] }}"
                                            data-plugin-name="{{ $plugin['plugin_name'] ?? 'Plugin' }}">
                                            @csrf
                                            <button type="submit" class="btn btn-warning btn-sm install-btn" {{ !$isActive ? 'disabled' : '' }}>
                                                <i class="fa fa-arrow-up"></i> Update to {{ $plugin['version'] }}
                                            </button>
                                        </form>
                                    @endif
                                    <!-- Uninstall button for installed plugin with update -->
                                    <form action="{{ route('plugin.market.uninstall', $plugin['id']) }}" method="POST"
                                        style="display:inline;" 
                                        class="uninstall-form"
                                        data-plugin-id="{{ $plugin['id'] }}"
                                        data-plugin-name="{{ $plugin['plugin_name'] ?? 'Plugin' }}">
                                        @csrf
                                        <button type="submit" class="btn btn-danger btn-sm uninstall-btn">
                                            <i class="fa fa-trash"></i> Uninstall
                                        </button>
                                    </form>
                                @else
                                    <!-- Plugin installed and up to date -->
                                    <button type="button" class="btn btn-secondary btn-sm" disabled>
                                        <i class="fa fa-check"></i> Installed ({{ $plugin['current_version'] }})
                                    </button>
                                    <!-- Uninstall button for installed plugin -->
                                    <form action="{{ route('plugin.market.uninstall', $plugin['id']) }}" method="POST"
                                        style="display:inline;" 
                                        class="uninstall-form"
                                        data-plugin-id="{{ $plugin['id'] }}"
                                        data-plugin-name="{{ $plugin['plugin_name'] ?? 'Plugin' }}">
                                        @csrf
                                        <button type="submit" class="btn btn-danger btn-sm uninstall-btn">
                                            <i class="fa fa-trash"></i> Uninstall
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
                                        Install
                                    </button>
                                @else
                                    <!-- Free plugin: install directly -->
                                    <form action="{{ route('plugin.market.install', $plugin['id']) }}" method="POST"
                                        style="display:inline;" 
                                        class="install-form"
                                        data-plugin-id="{{ $plugin['id'] }}"
                                        data-plugin-name="{{ $plugin['plugin_name'] ?? 'Plugin' }}">
                                        @csrf
                                        <button type="submit" class="btn btn-success btn-sm install-btn" {{ !$isActive ? 'disabled' : '' }}>Install</button>
                                    </form>
                                @endif
                            @endif
                            <a href="{{ route('plugin.market.show', $plugin['id']) }}" class="btn btn-primary btn-sm">Details</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="text-center text-muted py-3">Plugin not found</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<!-- License Key Modals for paid plugins -->
@foreach($plugins as $plugin)
    @if(isset($plugin['price']) && $plugin['price'] > 0)
        <div class="modal fade" id="licenseModal{{ $plugin['id'] }}" tabindex="-1" role="dialog">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Enter License Key</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <form action="{{ route('plugin.market.install', $plugin['id']) }}" method="POST" class="install-form-paid">
                        @csrf
                        <div class="modal-body">
                            <p>Plugin: <strong>{{ $plugin['plugin_name'] ?? 'Unknown' }}</strong></p>
                            <p>Price: <span class="text-danger">${{ number_format($plugin['price'], 2) }}</span></p>
                            <div class="form-group">
                                <label for="license_key{{ $plugin['id'] }}">License Key <span class="text-danger">*</span></label>
                                <input type="text" 
                                    class="form-control" 
                                    id="license_key{{ $plugin['id'] }}" 
                                    name="license_key" 
                                    placeholder="Enter your license key" 
                                    required>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-success install-paid-btn">Install Plugin</button>
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
document.addEventListener('DOMContentLoaded', function() {
    // Handle free plugin installation
    document.querySelectorAll('.install-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const btn = form.querySelector('.install-btn');
            const pluginName = form.dataset.pluginName;
            const formData = new FormData(form);
            
            // Disable button and show loading
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-2"></span>Installing...';
            
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
                    btn.innerHTML = '<i class="fa fa-check"></i> Installed';
                    btn.classList.remove('btn-success');
                    btn.classList.add('btn-secondary');
                    
                    // Show success message
                    if (typeof toastr !== 'undefined') {
                        toastr.success('Plugin "' + pluginName + '" installed successfully!');
                    } else {
                        alert('Plugin "' + pluginName + '" installed successfully!');
                    }
                    
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
                btn.innerHTML = 'Install';
                
                // Show error message
                const errorMsg = error.message || 'An error occurred while installing the plugin';
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
            
            if (!licenseKey.trim()) {
                alert('Please enter a license key');
                return;
            }
            
            // Disable button and show loading
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-2"></span>Installing...';
            
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
                    // Close modal
                    const modal = form.closest('.modal');
                    if (modal) {
                        $(modal).modal('hide');
                    }
                    
                    // Show success message
                    if (typeof toastr !== 'undefined') {
                        toastr.success('Plugin installed successfully!');
                    } else {
                        alert('Plugin installed successfully!');
                    }
                    
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
                btn.innerHTML = 'Install Plugin';
                
                // Show error message
                const errorMsg = error.message || 'An error occurred while installing the plugin';
                if (typeof toastr !== 'undefined') {
                    toastr.error(errorMsg);
                } else {
                    alert('Error: ' + errorMsg);
                }
            });
        });
    });
    
    // Handle plugin uninstallation
    document.querySelectorAll('.uninstall-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const pluginName = form.dataset.pluginName;
            
            // Confirm before uninstall
            if (!confirm('Are you sure you want to uninstall plugin "' + pluginName + '"?')) {
                return;
            }
            
            const btn = form.querySelector('.uninstall-btn');
            const formData = new FormData(form);
            
            // Disable button and show loading
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-2"></span>Uninstalling...';
            
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
                    // Show success message
                    if (typeof toastr !== 'undefined') {
                        toastr.success('Plugin "' + pluginName + '" uninstalled successfully!');
                    } else {
                        alert('Plugin "' + pluginName + '" uninstalled successfully!');
                    }
                    
                    // Reload page after 1 second
                    setTimeout(function() {
                        window.location.reload();
                    }, 1000);
                } else {
                    throw new Error(data.error || 'Uninstallation failed');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                btn.disabled = false;
                btn.innerHTML = '<i class="fa fa-trash"></i> Uninstall';
                
                // Show error message
                const errorMsg = error.message || 'An error occurred while uninstalling the plugin';
                if (typeof toastr !== 'undefined') {
                    toastr.error(errorMsg);
                } else {
                    alert('Error: ' + errorMsg);
                }
            });
        });
    });
});
</script>