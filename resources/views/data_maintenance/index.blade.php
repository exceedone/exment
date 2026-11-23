{{-- Data maintenance of a table (#1829): DataMaintenanceController --}}
@if(!empty($menu))
<div class="mb-2 text-end">{!! $menu !!}</div>
@endif

<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">{{ exmtrans('data_maintenance.attachments.header') }}</h3>
    </div>
    <div class="box-body">
        <p>{{ exmtrans('data_maintenance.attachments.help') }}</p>
        <ul class="text-muted">
            <li>{{ exmtrans('data_maintenance.attachments.help_keep_records') }}</li>
            <li>{{ exmtrans('data_maintenance.attachments.help_versions') }}</li>
            <li>{{ exmtrans('data_maintenance.attachments.help_revisions') }}</li>
            <li>{{ exmtrans('data_maintenance.attachments.help_trash') }}</li>
            <li>{{ exmtrans('data_maintenance.attachments.help_not_touched') }}</li>
        </ul>

        @if(isset($error))
        <div class="alert alert-danger">{{ $error }}</div>
        @endif

        <form method="get" action="{{ $checkUrl }}" class="form-horizontal" id="data-maintenance-check">
            <input type="hidden" name="check" value="1">
            <div class="form-group row mb-3">
                <label class="col-sm-2 control-label col-form-label">{{ exmtrans('data_maintenance.attachments.kinds') }}</label>
                <div class="col-sm-8">
                    @foreach(\Exceedone\Exment\Services\DataMaintenance\AttachmentCleanup::KINDS as $kind)
                    <div class="checkbox form-check">
                        <label>
                            <input type="checkbox" name="kinds[]" value="{{ $kind }}" {{ (in_array($kind, $kinds, true) || (!$checked && $kind === 'document')) ? 'checked' : '' }}>
                            {{ exmtrans('data_maintenance.attachments.kind.' . $kind) }}
                        </label>
                    </div>
                    @endforeach
                </div>
            </div>
            <div class="form-group row mb-3">
                <label class="col-sm-2 control-label col-form-label" for="data-maintenance-before">{{ exmtrans('data_maintenance.attachments.before') }}</label>
                <div class="col-sm-3">
                    <input type="date" class="form-control" id="data-maintenance-before" name="before" value="{{ $before }}">
                </div>
                <div class="col-sm-7">
                    <span class="help-block form-text">{{ exmtrans('data_maintenance.attachments.before_help') }}</span>
                </div>
            </div>
            <div class="form-group row">
                <div class="col-sm-offset-2 offset-sm-2 col-sm-8">
                    <button type="submit" class="btn btn-default btn-secondary">{{ exmtrans('data_maintenance.attachments.check') }}</button>
                </div>
            </div>
        </form>
    </div>
</div>

@if(isset($summary))
<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">{{ exmtrans('data_maintenance.attachments.result') }}</h3>
    </div>
    <div class="box-body">
        <table class="table table-bordered" style="max-width: 640px;">
            <tbody>
                @if(in_array('document', $kinds, true))
                <tr><th>{{ exmtrans('data_maintenance.attachments.count_documents') }}</th><td class="text-end">{{ number_format($summary['documents']) }}</td></tr>
                <tr><th>{{ exmtrans('data_maintenance.attachments.count_versions') }}</th><td class="text-end">{{ number_format($summary['versions']) }}</td></tr>
                @endif
                @if(in_array('column', $kinds, true))
                <tr><th>{{ exmtrans('data_maintenance.attachments.count_column_files') }}</th><td class="text-end">{{ number_format($summary['column_files']) }}</td></tr>
                @endif
                <tr><th>{{ exmtrans('data_maintenance.attachments.count_records') }}</th><td class="text-end">{{ number_format($summary['records']) }}</td></tr>
                <tr><th>{{ exmtrans('data_maintenance.attachments.size') }}</th><td class="text-end">{{ $size }}@if($summary['bytes_partial']) {{ exmtrans('data_maintenance.attachments.size_partial') }}@endif</td></tr>
            </tbody>
        </table>

        @if($summary['files'] === 0)
            <p>{{ exmtrans('data_maintenance.attachments.nothing') }}</p>
        @else
        <form method="post" action="{{ $deleteUrl }}" id="data-maintenance-delete"
            onsubmit="return window.confirm({{ json_encode(exmtrans('data_maintenance.attachments.confirm'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }});">
            <input type="hidden" name="_token" value="{{ csrf_token() }}">
            @foreach($kinds as $kind)
            <input type="hidden" name="kinds[]" value="{{ $kind }}">
            @endforeach
            <input type="hidden" name="before" value="{{ $before }}">
            <div class="alert alert-warning">{{ exmtrans('data_maintenance.attachments.warning') }}</div>
            <div class="form-group mb-3">
                <label for="data-maintenance-confirm">{{ exmtrans('data_maintenance.attachments.confirm_table_name', ['table_name' => $table->table_name]) }}</label>
                <input type="text" class="form-control" id="data-maintenance-confirm" name="confirm_table_name" autocomplete="off" style="max-width: 320px;" required>
            </div>
            <button type="submit" class="btn btn-danger">{{ exmtrans('data_maintenance.attachments.delete', ['files' => number_format($summary['files'])]) }}</button>
        </form>
        @endif
    </div>
</div>
@endif
