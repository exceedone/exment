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
                            <option value="page" {{ request('type') == 'page' ? 'selected' : '' }}>{{ exmtrans('plugin.type.page') }}</option>
                            <option value="dashboard" {{ request('type') == 'dashboard' ? 'selected' : '' }}>{{ exmtrans('plugin.type.dashboard') }}</option>
                            <option value="trigger" {{ request('type') == 'trigger' ? 'selected' : '' }}>{{ exmtrans('plugin.type.trigger') }}</option>
                            <option value="button" {{ request('type') == 'button' ? 'selected' : '' }}>{{ exmtrans('plugin.type.button') }}</option>
                            <option value="api" {{ request('type') == 'api' ? 'selected' : '' }}>{{ exmtrans('plugin.type.api') }}</option>
                            <option value="view" {{ request('type') == 'view' ? 'selected' : '' }}>{{ exmtrans('plugin.type.view') }}</option>
                            <option value="batch" {{ request('type') == 'batch' ? 'selected' : '' }}>{{ exmtrans('plugin.type.batch') }}</option>
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
@if(request()->hasAny(['keyword', 'type', 'status']))
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
        <div class="plugin-market-table-scroll">
            <table class="table table-hover mb-0 plugin-market-table">
            <thead class="table-dark">
                <tr>
                    <th>{{ exmtrans('plugin.market.id') }}</th>
                    <th>{{ exmtrans('plugin.market.name') }}</th>
                    <th>{{ exmtrans('plugin.market.internal_name') }}</th>
                    <th>{{ exmtrans('plugin.market.type') }}</th>
                    <th>{{ exmtrans('plugin.market.author') }}</th>
                    <th>{{ exmtrans('plugin.market.latest_version') }}</th>
                    <th>{{ exmtrans('plugin.market.price') }}</th>
                    <th>{{ exmtrans('plugin.market.description_col') }}</th>
                    <th>{{ exmtrans('plugin.market.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($plugins as $plugin)
                    <tr>
                        @php
    $isActive = isset($plugin['check_status']) && strtolower($plugin['check_status']) === 'active';
    $rawPrice = $plugin['price'] ?? null;
    $isFree = (bool) ($plugin['is_free'] ?? ((float) ($rawPrice ?? 0) <= 0));
    // Marketplace may return has_license=false even for free plugins.
    // For UI purposes, free plugins are always installable without payment.
    $hasLicense = $isFree ? true : (bool) ($plugin['has_license'] ?? false);
    $isExpired = (bool) ($plugin['is_expired'] ?? false);
    $canInstall = $isFree || ($hasLicense && !$isExpired);
    $shouldShowPayment = (!$isFree) && ((!$hasLicense) || $isExpired);
    $pluginUuid = $plugin['uuid'] ?? ($plugin['plugin_uuid'] ?? null);
                        @endphp
                        <td>{{ $plugin['id'] ?? '—' }}</td>
                        <td><strong>{{ $plugin['plugin_view_name'] ?? $plugin['plugin_name'] ?? '—' }}</strong></td>
                        <td><code>{{ $plugin['plugin_name'] ?? '—' }}</code></td>
                        <td><span class="badge bg-secondary">{{ $plugin['plugin_types'] ?? '—' }}</span></td>
                        <td>{{ $plugin['author'] ?? ($plugin['user']['name'] ?? '—') }}</td>
                        <td><span class="badge bg-info">{{ $plugin['version'] ?? '—' }}</span></td>
                        <td>
                            @if($isFree)
                                <span class="badge bg-success">{{ exmtrans('plugin.market.free') }}</span>
                            @elseif($rawPrice !== null && $rawPrice !== '')
                                @php
        $currencyLabel = $plugin['currency'] ?? null;
        $currencyNormalized = is_string($currencyLabel) ? strtoupper(trim($currencyLabel)) : null;
        $isYen = empty($currencyNormalized) || in_array($currencyNormalized, ['JPY', 'YEN', '円', '¥'], true);
        $priceLabel = is_numeric($rawPrice)
            ? ($isYen ? number_format((float) $rawPrice, 0) : number_format((float) $rawPrice, 2))
            : $rawPrice;
        $currencySuffix = is_numeric($rawPrice)
            ? ($isYen ? '円' : ($currencyLabel ?? null))
            : null;
                                @endphp
                                <span>{{ $priceLabel }}</span>
                                @if(!empty($currencySuffix))
                                    <small class="text-muted">{{ $currencySuffix }}</small>
                                @endif
                            @else
                                —
                            @endif
                        </td>
                        <td><small>{{ $plugin['description'] ?? '—' }}</small></td>
                        <td>
                            @if($isExpired)
                                <div class="text-warning" style="margin-bottom:4px;">
                                    <i class="fa fa-exclamation-triangle"></i> {{ exmtrans('plugin.market.message.expired_warning') }}
                                </div>
                            @endif

                            @if($shouldShowPayment)
                                <button type="button"
                                    class="btn btn-primary btn-sm purchase-btn"
                                    data-plugin-uuid="{{ $pluginUuid }}"
                                    data-plugin-id="{{ $plugin['id'] ?? '' }}"
                                    data-plugin-name="{{ $plugin['plugin_name'] ?? 'Plugin' }}"
                                    data-action="{{ $isExpired ? 'renew' : 'purchase' }}"
                                    {{ (empty($tenantUuid) || empty($pluginUuid) || !$isActive) ? 'disabled' : '' }}>
                                    {{ $isExpired ? exmtrans('plugin.market.renew') : exmtrans('plugin.market.payment') }}
                                </button>
                            @endif

                            @if($plugin['is_installed'] ?? false)
                                @if($plugin['has_update'] ?? false)
                                    <!-- Plugin installed but update available -->
                                    @if($canInstall)
                                        <!-- Show version selector modal -->
                                        <button type="button" class="btn btn-warning btn-sm" 
                                            data-toggle="modal" 
                                            data-target="#versionModal{{ $plugin['id'] }}"
                                            {{ !$isActive ? 'disabled' : '' }}>
                                            <i class="fa fa-arrow-up"></i> {{ exmtrans('plugin.market.update') }}
                                        </button>
                                    @else
                                        <!-- Payment/Renew handled by purchase button above -->
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
                                    @if($canInstall)
                                        <!-- Allow reinstall/downgrade -->
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
                                @if($canInstall)
                                    <!-- Show version selector modal -->
                                    <button type="button" class="btn btn-success btn-sm" 
                                        data-toggle="modal" 
                                        data-target="#versionModal{{ $plugin['id'] }}"
                                        {{ !$isActive ? 'disabled' : '' }}>
                                        {{ exmtrans('plugin.market.install') }}
                                    </button>
                                @else
                                    <!-- Payment/Renew handled by purchase button above -->
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
</div>

<!-- Version Selection Modals -->
@foreach($plugins as $plugin)
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

/* Mobile usability: allow scrolling in both directions while keeping table layout */
.plugin-market-table-scroll {
    overflow: auto;
    -webkit-overflow-scrolling: touch;
    width: 100%;
}

/* Prevent columns from collapsing too aggressively; rely on horizontal scroll */
.plugin-market-table {
    min-width: 900px;
}

/* On smaller screens, constrain height so vertical scroll is possible */
@media (max-width: 767px) {
    .plugin-market-table-scroll {
        max-height: 70vh;
    }
}

.plugin-market-table {
    background-color: white;
}

.install-btn:disabled {
    cursor: not-allowed;
    opacity: 0.6;
}

.spinner-border-sm {
    width: 1rem;
    height: 1rem;
    border-width: 0.15em;
}
.modal-header .close {
    margin-top: -20px;
}
</style>

<script>
// Wrap all initialization logic in a function
function initPluginMarket() {
    const t = {
        installing: {!! json_encode(exmtrans("plugin.market.message.installing")) !!},
        installedShort: {!! json_encode(exmtrans("plugin.market.installed_short")) !!},
        installSuccessTpl: {!! json_encode(exmtrans("plugin.market.message.install_success")) !!},
        installFailed: {!! json_encode(exmtrans("plugin.market.message.install_failed")) !!},
        install: {!! json_encode(exmtrans("plugin.market.install")) !!},
        uninstallConfirmTpl: {!! json_encode(exmtrans("plugin.market.message.uninstall_confirm")) !!},
        uninstallConfirmText: {!! json_encode(exmtrans("plugin.market.message.uninstall_confirm_text")) !!},
        uninstall: {!! json_encode(exmtrans("plugin.market.uninstall")) !!},
        cancel: {!! json_encode(exmtrans("plugin.market.version_modal.cancel")) !!},
        selectVersion: {!! json_encode(exmtrans("plugin.market.version_modal.select_version")) !!},
        latest: {!! json_encode(exmtrans("plugin.market.version_modal.latest")) !!},
        noVersions: {!! json_encode(exmtrans("plugin.market.message.no_versions")) !!},
        versionLoadFailed: {!! json_encode(exmtrans("plugin.market.message.version_load_failed")) !!},
        changelog: {!! json_encode(exmtrans("plugin.market.version_modal.changelog")) !!},
        pleaseSelectVersion: {!! json_encode(exmtrans("plugin.market.message.please_select_version")) !!},
        installVersion: {!! json_encode(exmtrans("plugin.market.version_modal.install")) !!},
        paymentProcessing: {!! json_encode(exmtrans("plugin.market.message.payment_processing")) !!},
        renewProcessing: {!! json_encode(exmtrans("plugin.market.message.renew_processing")) !!},
        paymentFailed: {!! json_encode(exmtrans("plugin.market.message.payment_failed")) !!},
        noticeTitle: {!! json_encode(exmtrans("plugin.market.message.notice_title")) !!},
        ok: {!! json_encode(exmtrans("plugin.market.message.ok")) !!},
        manualPaymentRequired: {!! json_encode(exmtrans("plugin.market.message.manual_payment_required")) !!},
        missingTenantUuid: {!! json_encode(exmtrans("plugin.market.message.missing_tenant_uuid")) !!},
        missingPluginUuid: {!! json_encode(exmtrans("plugin.market.message.missing_plugin_uuid")) !!},
        stripeLoadFailed: {!! json_encode(exmtrans("plugin.market.message.stripe_load_failed")) !!},
        stripePublishableKeyMissing: {!! json_encode(exmtrans("plugin.market.message.stripe_publishable_key_missing")) !!},
        missingClientSecret: {!! json_encode(exmtrans("plugin.market.message.missing_client_secret")) !!},
        paymentSucceeded: {!! json_encode(exmtrans("plugin.market.message.payment_succeeded")) !!},
        paymentStatusTpl: {!! json_encode(exmtrans("plugin.market.message.payment_status")) !!},
        errorPrefixTpl: {!! json_encode(exmtrans("plugin.market.message.error_prefix")) !!},
    };

    const adminPluginMarketUrl = {!! json_encode(admin_url('plugin-market')) !!};
    const marketplaceUrl = {!! json_encode(rtrim(config('exment.market_plugin_url', 'https://exment.org'), '/')) !!};
    const tenantUuid = {!! json_encode($tenantUuid ?? null) !!};
    const stripePublishableKey = {!! json_encode(config('services.stripe.key') ?? null) !!};
    const csrfToken = {!! json_encode(csrf_token()) !!};

    // Catch any unhandled JS errors on this page and show a red toast.
    // Guard to avoid registering multiple times (PJAX re-init).
    if (!window.__exmentPluginMarketErrorHandlersInstalled) {
        window.__exmentPluginMarketErrorHandlersInstalled = true;

        window.addEventListener('error', function (event) {
            try {
                const msg = (event && event.error && event.error.message)
                    ? event.error.message
                    : (event && event.message ? event.message : null);
                if (typeof toastr !== 'undefined') {
                    toastr.error(msg || t.paymentFailed);
                }
            } catch (e) {
                // ignore
            }
        });

        window.addEventListener('unhandledrejection', function (event) {
            try {
                const reason = event ? event.reason : null;
                const msg = reason && reason.message ? reason.message : (typeof reason === 'string' ? reason : null);
                if (typeof toastr !== 'undefined') {
                    toastr.error(msg || t.paymentFailed);
                }
            } catch (e) {
                // ignore
            }
        });
    }

    function showToast(type, message) {
        if (typeof toastr !== 'undefined') {
            toastr[type](message);
        } else {
            alert(message);
        }
    }

    function showPopupAndRedirect(message, url, onCancel, onConfirm) {
        // Prefer SweetAlert2 if available (laravel-admin ships it).
        if (window.Swal && typeof window.Swal.fire === 'function') {
            window.Swal.fire({
                icon: 'info',
                title: t.noticeTitle,
                text: message,
                confirmButtonText: t.ok,
                showCancelButton: true,
                cancelButtonText: t.cancel,
                allowOutsideClick: false,
            }).then(function(result) {
                // SweetAlert2 versions differ:
                // - newer: result.isConfirmed === true
                // - some setups: result.value === true
                if (result && (result.isConfirmed === true || result.value === true)) {
                    window.open(url, '_blank', 'noopener');
                    if (typeof onConfirm === 'function') {
                        onConfirm();
                    }
                } else if (typeof onCancel === 'function') {
                    onCancel();
                }
            });
            return;
        }

        // Fallback: use native confirm() to support OK/Cancel.
        if (window.confirm(message)) {
            window.open(url, '_blank', 'noopener');
            if (typeof onConfirm === 'function') {
                onConfirm();
            }
        } else if (typeof onCancel === 'function') {
            onCancel();
        }
    }

    function ensureStripeLoaded() {
        return new Promise(function(resolve, reject) {
            if (window.Stripe) {
                resolve();
                return;
            }
            const script = document.createElement('script');
            script.src = 'https://js.stripe.com/v3/';
            script.onload = resolve;
            script.onerror = function() { reject(new Error(t.stripeLoadFailed)); };
            document.head.appendChild(script);
        });
    }

    async function handlePurchase(button) {
        const pluginUuid = button.dataset.pluginUuid;
        const pluginName = button.dataset.pluginName || 'Plugin';
        const action = button.dataset.action || 'purchase';

        if (!tenantUuid) {
            showToast('error', t.missingTenantUuid);
            return;
        }
        if (!pluginUuid) {
            showToast('error', t.missingPluginUuid);
            return;
        }

        const originalHtml = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<span class="spinner-border spinner-border-sm mr-2"></span>' + (action === 'renew' ? t.renewProcessing : t.paymentProcessing);

        try {
            // Call same-origin proxy endpoint to avoid browser CORS restrictions.
            const response = await fetch(`${adminPluginMarketUrl}/checkout/purchase`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({
                    tenant_uuid: tenantUuid,
                    plugin_uuid: pluginUuid
                })
            });

            let data = {};
            let rawText = '';
            try {
                rawText = await response.text();
                data = rawText ? JSON.parse(rawText) : {};
            } catch (e) {
                data = {};
            }

            // Special case: treat HTTP 402 as a "requires_action" (3DS) flow.
            // Some backends use 402 to indicate additional authentication is required.
            if (!response.ok && response.status !== 402) {
                const msg = (data && (data.error || data.message)) ? (data.error || data.message)
                    : (rawText && rawText.length < 500 ? rawText : null);
                throw new Error(msg || t.paymentFailed);
            }

            // Treat HTTP 402 as a manual payment required flow.
            // Even if a client_secret is provided, we intentionally do NOT run 3DS from this UI.
            if (response.status === 402) {
                const manualMessage = (data && (data.message || data.error)) ? (data.message || data.error) : t.manualPaymentRequired;
                const manualUrl = 'https://exment.org/payment-methods';

                const restoreButton = function() {
                    button.disabled = false;
                    button.innerHTML = originalHtml;
                };

                showPopupAndRedirect(manualMessage, manualUrl, restoreButton, restoreButton);
                return;
            }

            let effectiveStatus = (response.status === 402)
                ? 'requires_action'
                : (data && (data.status !== undefined && data.status !== null) ? data.status : null);

            // Normalize status values from the marketplace.
            // Some APIs return "success" (or boolean success flags) instead of Stripe-like "succeeded".
            const normalizedStatus = (typeof effectiveStatus === 'string')
                ? effectiveStatus.toLowerCase()
                : effectiveStatus;
            const isSuccess = normalizedStatus === 'succeeded'
                || normalizedStatus === 'success'
                || normalizedStatus === 'paid'
                || normalizedStatus === 'completed'
                || normalizedStatus === 'ok'
                || (data && data.success === true);

            if (isSuccess) {
                showToast('success', data.message || t.paymentSucceeded);
                setTimeout(function() { window.location.reload(); }, 1200);
                return;
            }

            if (normalizedStatus === 'requires_action') {
                const manualMessage = (data && (data.message || data.error)) ? (data.message || data.error) : t.manualPaymentRequired;
                const manualUrl = 'https://exment.org/payment-methods';

                const restoreButton = function() {
                    button.disabled = false;
                    button.innerHTML = originalHtml;
                };

                showPopupAndRedirect(manualMessage, manualUrl, restoreButton, restoreButton);
                return;
            }

            if (normalizedStatus === 'failed' || normalizedStatus === 'error') {
                throw new Error(data.error || data.message || t.paymentFailed);
            }

            throw new Error(data.message || t.paymentFailed);
        } catch (error) {
            console.error('Purchase error:', error);
            showToast('error', (error && error.message) ? error.message : t.paymentFailed);
            button.disabled = false;
            button.innerHTML = originalHtml;
        }
    }

    document.querySelectorAll('.purchase-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            handlePurchase(btn);
        });
    });

    // Handle free plugin installation
    document.querySelectorAll('.install-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const btn = form.querySelector('.install-btn');
            const pluginName = form.dataset.pluginName;
            const formData = new FormData(form);
            
            // Disable button and show loading
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-2"></span>' + t.installing;
            
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
                    btn.innerHTML = '<i class="fa fa-check"></i> ' + t.installedShort;
                    btn.classList.remove('btn-success');
                    btn.classList.add('btn-secondary');
                    
                    // Show success message
                    if (typeof toastr !== 'undefined') {
                        toastr.success(t.installSuccessTpl.replace(':name', pluginName));
                    } else {
                        alert(t.installSuccessTpl.replace(':name', pluginName));
                    }
                    
                    // Reload page after 2 seconds
                    setTimeout(function() {
                        window.location.reload();
                    }, 2000);
                } else {
                    throw new Error(data.error || t.installFailed);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                btn.disabled = false;
                btn.innerHTML = t.install;
                
                // Show error message
                const errorMsg = error.message || t.installFailed;
                if (typeof toastr !== 'undefined') {
                    toastr.error(errorMsg);
                } else {
                    alert(t.errorPrefixTpl.replace(':message', errorMsg));
                }
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
            const confirmTitle = t.uninstallConfirmTpl.replace(':name', pluginName);
            const url = adminPluginMarketUrl + '/' + pluginId + '/uninstall';
            
            // Use Exment's standard ShowSwal pattern with redirect to reload current page
            Exment.CommonEvent.ShowSwal(url, {
                title: confirmTitle,
                text: t.uninstallConfirmText,
                type: 'warning',
                method: 'POST',
                confirm: t.uninstall,
                cancel: t.cancel,
                data: {},
                redirect: adminPluginMarketUrl  // Reload plugin market page after success
            });
        });
    });

    // Load versions when modal is opened
    $('.modal[id^="versionModal"]').on('show.bs.modal', function(e) {
        const modal = $(this);
        const pluginId = modal.attr('id').match(/\d+/)[0];
        const versionSelect = modal.find('.version-select');
        
        console.log('Loading versions for plugin:', pluginId);
        
        // Load versions from API
        const params = new URLSearchParams();
        if (tenantUuid) {
            params.set('tenant_uuid', tenantUuid);
        }
        const query = params.toString() ? `?${params.toString()}` : '';
        fetch(`${marketplaceUrl}/api/plugins/${pluginId}/versions${query}`)
            .then(response => response.json())
            .then(data => {
                console.log('Versions data:', data);
                
                versionSelect.empty();
            versionSelect.append($('<option></option>').attr('value', '').text(t.selectVersion));
                
                if (data.versions && data.versions.length > 0) {
                    data.versions.forEach(function(version) {
                        // Build label including latest flag
                        let label = version.version;
                        const isLatest = !!version.is_latest;

                        let downloadUrl = version.download_url || '';
                        const hasTenantUuid = downloadUrl.includes('tenant_uuid=');
                        const isFileUrl = downloadUrl.startsWith('file://');
                        const looksSigned = downloadUrl.includes('signature=') || downloadUrl.includes('X-Amz-Signature=') || downloadUrl.includes('X-Amz-Credential=');
                        if (downloadUrl && tenantUuid && !hasTenantUuid && !isFileUrl && !looksSigned) {
                            downloadUrl += (downloadUrl.includes('?') ? '&' : '?') + 'tenant_uuid=' + encodeURIComponent(tenantUuid);
                        }

                        if (isLatest) {
                            label += ` (${t.latest})`;
                        }
                        
                        const option = $('<option></option>')
                            .attr('value', version.id)
                            .attr('data-changelog', version.changelog || '')
                            .attr('data-download-url', downloadUrl)
                            .text(label);
                        versionSelect.append(option);
                    });
                    
                    // Select latest version by default
                    const latestVersion = data.versions.find(v => v.is_latest);
                    if (latestVersion) {
                        versionSelect.val(latestVersion.id).trigger('change');
                    }
                } else {
                    versionSelect.append($('<option></option>').attr('value', '').text(t.noVersions));
                }
            })
            .catch(error => {
                console.error('Error loading versions:', error);
                versionSelect.empty();
                versionSelect.append($('<option></option>').attr('value', '').text(t.versionLoadFailed));
            });
    });
    
    // Show changelog when version is selected
    $('.version-select').on('change', function() {
        const selected = $(this).find('option:selected');
        const changelog = selected.attr('data-changelog');
        const pluginId = $(this).data('plugin-id');
        const changelogElement = $('#changelog' + pluginId);
        
        if (changelog) {
            changelogElement.text(t.changelog + ': ' + changelog);
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
                alert(t.pleaseSelectVersion);
                return;
            }
            
            // Disable button and show loading
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-2"></span>' + t.installing;
            
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
                    throw new Error(data.error || t.installFailed);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                btn.disabled = false;
                btn.innerHTML = t.installVersion;
                
                const errorMsg = error.message || t.installFailed;
                if (typeof toastr !== 'undefined') {
                    toastr.error(errorMsg);
                } else {
                    alert(t.errorPrefixTpl.replace(':message', errorMsg));
                }
            });
        });
    });
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