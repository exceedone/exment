<div class="container-fluid plugin-detail-page">
    <div class="row">
        <div class="col-12">
            <div class="panel panel-default panel-plugin-detail">
                <div class="panel-heading" style="display: flex; justify-content: space-between; align-items: center;">
                    <h3 class="panel-title mb-0">Plugin Detail</h3>
                    <div class="btn-group pull-right" style="margin-right: 5px">
                        <a href="{{ route('plugin.market.index') }}" class="btn btn-sm btn-default" title="List">
                            <i class="fa fa-list"></i><span class="hidden-xs">&nbsp;List</span>
                        </a>
                    </div>
                </div>
                <div class="panel-body p-0">
                    <section class="setting-section">
                        <div class="setting-content">
                            <div class="row-setting">
                                <div class="label">Plugin ID</div>
                                <div class="value"><span>{{ $plugin['id'] ?? '—' }}</span></div>
                            </div>
                            <div class="row-setting">
                                <div class="label">Plugin Name</div>
                                <div class="value">{{ $plugin['plugin_name'] ?? '—' }}</div>
                            </div>
                            <div class="row-setting">
                                <div class="label">Plugin View Name</div>
                                <div class="value">{{ $plugin['plugin_view_name'] ?? '—' }}</div>
                            </div>
                            <div class="row-setting">
                                <div class="label">Plugin Type</div>
                                <div class="value"><span
                                        class="badge bg-info">{{ $plugin['plugin_types'] ?? '—' }}</span></div>
                            </div>
                            <div class="row-setting">
                                <div class="label">Author</div>
                                <div class="value">{{ $plugin['user']['name'] ?? '—' }}</div>
                            </div>
                            <div class="row-setting">
                                <div class="label">Version</div>
                                <div class="value">{{ $plugin['version'] ?? '—' }}</div>
                            </div>
                            <div class="row-setting">
                                <div class="label">Description</div>
                                <div class="value">{{ $plugin['description'] ?? '—' }}</div>
                            </div>

                            <div class="row-setting">
                                <div class="label">Active Flg</div>
                                <div class="value">
                                    <span
                                        class="ms-2 fw-bold text-muted">{{ $plugin['check_status'] == 'active' ? 'Available' : 'Unavailable' }}</span>
                                </div>
                            </div>
                        </div>
                    </section>


                    <!-- Footer: detail-only, no actions -->
                    <div class="panel-footer px-3 py-2 bg-light text-end">
                        <small class="text-muted">Read-only view</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .panel-plugin-detail {
        border: 1px solid #d9e2ef;
        background: #fff;
    }

    .panel-heading {
        background: #f8fbff;
        border-bottom: 1px solid #d9e2ef;
        padding: 10px 15px;
    }

    .panel-title {
        font-size: 16px;
        font-weight: 600;
    }

    .action-group .btn {
        margin-left: 4px;
    }

    .setting-section {
        padding: 15px 20px 5px;
    }

    .setting-section+.setting-section {
        border-top: 1px solid #e5e5e5;
    }

    .section-title {
        font-size: 15px;
        font-weight: 600;
        margin-bottom: 10px;
        color: #555;
    }

    .setting-content {
        margin-bottom: 10px;
    }

    .row-setting {
        display: flex;
        padding: 6px 0;
        border-bottom: 1px dotted #eee;
    }

    .row-setting:last-child {
        border-bottom: none;
    }

    .row-setting .label {
        flex: 0 0 180px;
        font-weight: 600;
        color: #444;
        font-size: 13px;
    }

    .row-setting .value {
        flex: 1;
        font-size: 13px;
    }

    .panel-footer {
        border-top: 1px solid #d9e2ef;
    }

    /* Toggle switch */
    .switch {
        position: relative;
        display: inline-block;
        width: 46px;
        height: 22px;
    }

    .switch input {
        opacity: 0;
        width: 0;
        height: 0;
    }

    .slider {
        position: absolute;
        cursor: not-allowed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: #ccc;
        transition: .3s;
        border-radius: 34px;
    }

    .slider:before {
        position: absolute;
        content: "";
        height: 16px;
        width: 16px;
        left: 4px;
        bottom: 3px;
        background-color: white;
        transition: .3s;
        border-radius: 50%;
    }

    input:checked+.slider {
        background-color: #4caf50;
    }

    input:checked+.slider:before {
        transform: translateX(24px);
    }

    .badge {
        font-size: 11px;
    }

    /* Removed form-control width since view is read-only */
    @media (max-width: 767px) {
        .row-setting {
            flex-direction: column;
        }

        .row-setting .label {
            width: 100%;
            margin-bottom: 4px;
        }
    }
</style>