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
    {{ exmtrans('plugin.market.search.results_found', ['count' => ($plugins instanceof \Illuminate\Pagination\LengthAwarePaginator) ? $plugins->total() : count($plugins)]) }}
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
                    <th>{{ exmtrans('plugin.market.name') }}</th>
                    <th>{{ exmtrans('plugin.market.internal_name') }}</th>
                    <th>{{ exmtrans('plugin.market.type') }}</th>
                    <th>{{ exmtrans('plugin.market.author') }}</th>
                    <th>{{ exmtrans('plugin.market.latest_version') }}</th>
                    <th>{{ exmtrans('plugin.market.price') }}</th>
                    <th>{{ exmtrans('plugin.market.license') }}</th>
                    <th>{{ exmtrans('plugin.market.description_col') }}</th>
                    <th>{{ exmtrans('plugin.market.status') }}</th>
                    <th>{{ exmtrans('plugin.market.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($plugins as $plugin)
                    <tr>
                        <td><strong>{{ $plugin['plugin_name'] ?? '—' }}</strong></td>
                        <td>{{ $plugin['plugin_view_name'] ?? '—' }}</td>
                        <td><span class="badge bg-secondary">{{ $plugin['plugin_types_display'] }}</span></td>
                        <td>{{ $plugin['user']['name'] ?? ($plugin['author'] ?? '—') }}</td>
                        <td><span class="badge bg-info">{{ $plugin['version'] ?? '—' }}</span></td>
                        <td>
                            @if($plugin['is_free'])
                                <span class="badge bg-success">{{ exmtrans('plugin.market.free') }}</span>
                            @elseif(!empty($plugin['price_label']))
                                <span>{{ $plugin['price_label'] }}</span>
                                @if(!empty($plugin['currency_suffix']))
                                    <small class="text-muted">{{ $plugin['currency_suffix'] }}</small>
                                @endif
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            @if($plugin['is_free'])
                                <span class="badge bg-success">{{ exmtrans('plugin.market.free') }}</span>
                            @elseif($plugin['is_owner'] ?? false)
                                <span class="badge bg-primary">{{ exmtrans('plugin.market.owner') }}</span>
                            @elseif($plugin['has_license'] && !$plugin['is_expired'])
                                @if(!empty($plugin['expired_at']))
                                    <span class="badge bg-success">{{ exmtrans('plugin.market.license_expiry', ['date' => \Carbon\Carbon::parse($plugin['expired_at'])->format('Y/m/d')]) }}</span>
                                @else
                                    <span class="badge bg-success">{{ exmtrans('plugin.market.license_permanent') }}</span>
                                @endif
                            @elseif($plugin['is_expired'])
                                <span class="badge bg-danger">{{ exmtrans('plugin.market.message.expired_warning') }}</span>
                            @else
                                <span class="badge bg-secondary">{{ exmtrans('plugin.market.no_license') }}</span>
                            @endif
                        </td>
                        <td><small>{{ $plugin['description'] ?? '—' }}</small></td>
                        <td>
                            <span class="badge {{ $plugin['status_badge_class'] }}">
                                {{ exmtrans('plugin.status.' . ($plugin['plugin_status'] ?? 'not_installed')) }}
                            </span>
                        </td>
                        <td>
                            @if($plugin['is_expired'])
                                <div class="text-warning" style="margin-bottom:4px;">
                                    <i class="fa fa-exclamation-triangle"></i> {{ exmtrans('plugin.market.message.expired_warning') }}
                                </div>
                            @endif

                            @if($plugin['should_show_payment'])
                                <button type="button"
                                    class="btn btn-primary btn-sm purchase-btn"
                                    data-plugin-uuid="{{ $plugin['display_uuid'] }}"
                                    data-plugin-id="{{ $plugin['id'] ?? '' }}"
                                    data-plugin-name="{{ $plugin['plugin_name'] ?? 'Plugin' }}"
                                    data-action="{{ $plugin['is_expired'] ? 'renew' : 'purchase' }}"
                                    {{ (empty($tenantUuid) || empty($plugin['display_uuid']) || !$plugin['is_active']) ? 'disabled' : '' }}>
                                    {{ $plugin['is_expired'] ? exmtrans('plugin.market.renew') : exmtrans('plugin.market.payment') }}
                                </button>
                            @endif

                            @if($plugin['is_installed'] ?? false)
                                @if($plugin['has_update'] ?? false)
                                    <!-- Plugin installed but update available -->
                                    @if(($plugin['is_market_plugin'] ?? true) && $plugin['can_install'])
                                        <!-- Show version selector modal -->
                                        <button type="button" class="btn btn-warning btn-sm" 
                                            data-toggle="modal" 
                                            data-target="#versionModal{{ $plugin['id'] }}"
                                            {{ !$plugin['is_active'] ? 'disabled' : '' }}>
                                            <i class="fa fa-arrow-up"></i> {{ exmtrans('plugin.market.update') }}
                                        </button>
                                    @endif
                                    <!-- Uninstall button for installed plugin with update -->
                                    <form action="{{ route('plugin.market.uninstall', 'local-' . $plugin['local_db_id']) }}" method="POST"
                                        style="display:inline;" 
                                        class="uninstall-form"
                                        data-plugin-id="{{ 'local-' . $plugin['local_db_id'] }}"
                                        data-plugin-name="{{ $plugin['plugin_name'] ?? 'Plugin' }}">
                                        @csrf
                                        <button type="submit" class="btn btn-danger btn-sm uninstall-btn">
                                            <i class="fa fa-trash"></i> {{ exmtrans('plugin.market.uninstall') }}
                                        </button>
                                    </form>
                                @else
                                    @if(($plugin['is_market_plugin'] ?? true) && $plugin['can_install'])
                                        <!-- Allow reinstall/downgrade -->
                                        <button type="button" class="btn btn-info btn-sm" 
                                            data-toggle="modal" 
                                            data-target="#versionModal{{ $plugin['id'] }}"
                                            {{ !$plugin['is_active'] ? 'disabled' : '' }}>
                                            <i class="fa fa-refresh"></i> {{ exmtrans('plugin.market.install') }}
                                        </button>
                                    @endif
                                    <!-- Uninstall button for installed plugin -->
                                    <form action="{{ route('plugin.market.uninstall', 'local-' . $plugin['local_db_id']) }}" method="POST"
                                        style="display:inline;" 
                                        class="uninstall-form"
                                        data-plugin-id="{{ 'local-' . $plugin['local_db_id'] }}"
                                        data-plugin-name="{{ $plugin['plugin_name'] ?? 'Plugin' }}">
                                        @csrf
                                        <button type="submit" class="btn btn-danger btn-sm uninstall-btn">
                                            <i class="fa fa-trash"></i> {{ exmtrans('plugin.market.uninstall') }}
                                        </button>
                                    </form>
                                @endif
                            @else
                                <!-- Plugin not installed -->
                                @if($plugin['can_install'])
                                    <!-- Show version selector modal -->
                                    <button type="button" class="btn btn-success btn-sm" 
                                        data-toggle="modal" 
                                        data-target="#versionModal{{ $plugin['id'] }}"
                                        {{ !$plugin['is_active'] ? 'disabled' : '' }}>
                                        {{ exmtrans('plugin.market.install') }}
                                    </button>
                                @else
                                    <!-- Payment/Renew handled by purchase button above -->
                                @endif
                            @endif
                            @if($plugin['is_installed'] ?? false)
                                <a href="{{ admin_url('plugin/' . $plugin['local_db_id'] . '/edit') }}" class="btn btn-default btn-sm">
                                    <i class="fa fa-cog"></i> {{ exmtrans('plugin.market.setting') }}
                                </a>
                            @else
                                <a href="{{ route('plugin.market.show', $plugin['route_key']) }}" class="btn btn-primary btn-sm">{{ exmtrans('plugin.market.details') }}</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="text-center text-muted py-3">{{ exmtrans('plugin.market.plugin_not_found') }}</td>
                    </tr>
                @endforelse
            </tbody>
            </table>
        </div>
    </div>

    @if($plugins instanceof \Illuminate\Pagination\LengthAwarePaginator)
        <div class="box-footer clearfix">
            @php
                $currentPerPage = (int) request('per_page', $plugins->perPage());
                if ($currentPerPage <= 0) { $currentPerPage = (int) $plugins->perPage(); }
                $perPageOptions = [10, 20, 50, 100, 200];

                $rangeFirst = $plugins->firstItem() ?? 0;
                $rangeLast = $plugins->lastItem() ?? 0;
                $rangeTotal = $plugins->total() ?? 0;
                $rangeParams = collect([
                    'first' => $rangeFirst,
                    'last'  => $rangeLast,
                    'total' => $rangeTotal,
                ])->flatMap(function ($val, $key) {
                    return [$key => "<b>$val</b>"];
                })->all();
            @endphp

            <div class="pull-left" style="padding-top: 4px;">
                {!! trans('admin.pagination.range', $rangeParams) !!}
            </div>

            <div class="pull-right" style="text-align: right;">
                <span style="display:inline-block; vertical-align: middle; margin-right: 10px;">
                    <label class="control-label" style="margin: 0; font-weight: 100;">
                        <small>{{ trans('admin.show') }}</small>&nbsp;
                        <select class="input-sm" name="per-page" onchange="window.location.href=this.value;">
                            @foreach($perPageOptions as $opt)
                                @php
                                    $url = request()->fullUrlWithQuery(['per_page' => $opt, 'page' => 1]);
                                @endphp
                                <option value="{{ $url }}" {{ $currentPerPage == $opt ? 'selected' : '' }}>{{ $opt }}</option>
                            @endforeach
                        </select>
                        &nbsp;<small>{{ trans('admin.entries') }}</small>
                    </label>
                </span>

                <span style="display:inline-block; vertical-align: middle;">
                    {!! $plugins->render('admin::pagination') !!}
                </span>
            </div>
        </div>
    @endif
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
                    <form action="{{ route('plugin.market.install', $plugin['id']) }}" method="POST" class="install-form-free" data-plugin-name="{{ $plugin['plugin_name'] ?? 'Plugin' }}">
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
    const marketplaceApiKey = {!! json_encode(config('exment.ai_server_api_key', '')) !!};
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
                const manualUrl = marketplaceUrl + '/payment-methods';

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
                const manualUrl = marketplaceUrl + '/payment-methods';

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
        // Prevent duplicate bindings when initPluginMarket() runs multiple times (PJAX).
        if (btn.dataset && btn.dataset.exmentPluginMarketBoundPurchase === '1') {
            return;
        }
        if (btn.dataset) {
            btn.dataset.exmentPluginMarketBoundPurchase = '1';
        }

        btn.addEventListener('click', function(e) {
            e.preventDefault();
            handlePurchase(btn);
        });
    });

    // Handle free plugin installation
    document.querySelectorAll('.install-form').forEach(function(form) {
        if (form.dataset && form.dataset.exmentPluginMarketBoundInstall === '1') {
            return;
        }
        if (form.dataset) {
            form.dataset.exmentPluginMarketBoundInstall = '1';
        }

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
        if (btn.dataset && btn.dataset.exmentPluginMarketBoundUninstall === '1') {
            return;
        }
        if (btn.dataset) {
            btn.dataset.exmentPluginMarketBoundUninstall = '1';
        }

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
    // Use delegated, namespaced handlers to avoid duplicate bindings across PJAX re-inits.
    $(document)
        .off('show.bs.modal.exmentPluginMarket', '.modal[id^="versionModal"]')
        .on('show.bs.modal.exmentPluginMarket', '.modal[id^="versionModal"]', function(e) {
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
        fetch(`${marketplaceUrl}/api/plugins/${pluginId}/versions${query}`, {
                headers: Object.assign(
                    { 'Accept': 'application/json' },
                    marketplaceApiKey ? { 'Authorization': 'Bearer ' + marketplaceApiKey } : {}
                )
            })
            .then(async (response) => {
                const rawText = await response.text();
                let data = {};
                try {
                    data = rawText ? JSON.parse(rawText) : {};
                } catch (e) {
                    // Marketplace sometimes returns an HTML error page (starts with '<'),
                    // which would break response.json(). Normalize to a handled error.
                    throw new Error(t.versionLoadFailed);
                }

                if (!response.ok) {
                    throw new Error((data && (data.error || data.message)) ? (data.error || data.message) : t.versionLoadFailed);
                }

                return data;
            })
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
    $(document)
        .off('change.exmentPluginMarket', '.version-select')
        .on('change.exmentPluginMarket', '.version-select', function() {
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
        if (form.dataset && form.dataset.exmentPluginMarketBoundInstallFree === '1') {
            return;
        }
        if (form.dataset) {
            form.dataset.exmentPluginMarketBoundInstallFree = '1';
        }

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const btn = form.querySelector('.install-free-btn');
            const pluginName = form.dataset.pluginName || 'Plugin';
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
                    'X-CSRF-TOKEN': formData.get('_token'),
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
            .then(async (response) => {
                const rawText = await response.text();
                let data = null;
                try {
                    data = rawText ? JSON.parse(rawText) : null;
                } catch (e) {
                    data = null;
                }

                // If the install succeeded but the response is non-JSON (e.g. HTML due to redirect/error page),
                // avoid surfacing a JSON parse error. Reload to reflect the final install state.
                if (response.ok && !data) {
                    return { __nonJsonOk: true };
                }

                if (!response.ok) {
                    const msg = (data && (data.error || data.message)) ? (data.error || data.message)
                        : (rawText && rawText.length < 500 ? rawText : null);
                    throw new Error(msg || t.installFailed);
                }

                return data || {};
            })
            .then(data => {
                if (data && data.__nonJsonOk) {
                    // Response was OK but not JSON (possibly HTML). Reload to reflect final install state.
                    // Keep the modal open; it will disappear when the page reloads.
                    setTimeout(function() {
                        window.location.reload();
                    }, 100);
                    return;
                }

                if (data.success) {
                    // Keep the modal open and reload. The page flash message (e.g. "実行完了しました！")
                    // will be shown after reload.
                    setTimeout(function() {
                        window.location.reload();
                    }, 100);
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

// Bootstrap only once to avoid duplicated init triggers if this script is evaluated multiple times.
if (!window.__exmentPluginMarketBootstrapped) {
    window.__exmentPluginMarketBootstrapped = true;

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', initPluginMarket);

    // Re-initialize after PJAX navigation (for Exment/Laravel-Admin)
    $(document)
        .off('pjax:end.exmentPluginMarket')
        .on('pjax:end.exmentPluginMarket', function() {
            console.log('[PluginMarket] PJAX navigation detected, reinitializing...');
            // Wait a bit for DOM to be ready
            setTimeout(initPluginMarket, 100);
        });
} else {
    // If the script gets evaluated again (PJAX), init for the current fragment.
    setTimeout(initPluginMarket, 0);
}

</script>