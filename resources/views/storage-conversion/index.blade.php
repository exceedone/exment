@include('exment::storage-conversion.execution', ['execution' => $execution])

<div class="box card p-3">
    <div class="box-header with-border d-flex align-items-center border-bottom pb-2 mb-3">
        <h3 class="box-title fs-5 m-0"><i class="fa fa-table"></i> {{ exmtrans('storage_conversion.tables') }}</h3>
    </div>
    <div class="box-body table-responsive">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>{{ exmtrans('storage_conversion.col_table') }}</th>
                    <th>{{ exmtrans('storage_conversion.col_mode') }}</th>
                    <th>{{ exmtrans('storage_conversion.col_state') }}</th>
                    <th>{{ exmtrans('storage_conversion.col_generation') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($tables as $row)
                <tr @if(!$row['system_table']) class="exm-storage-row" style="cursor: pointer;" tabindex="0" @endif>
                    <td>{{ $row['table_view_name'] }} <small class="text-muted">({{ $row['table_name'] }})</small></td>
                    @if($row['storage'])
                        <td>{{ $row['storage']['mode'] === 'physical' ? exmtrans('custom_table.storage_mode_physical') : ($row['storage']['mode'] === 'json' ? exmtrans('custom_table.storage_mode_json') : $row['storage']['mode']) }}</td>
                        <td>{{ exmtrans('storage_conversion.state_' . $row['storage']['state']) }}</td>
                        <td>{{ $row['storage']['generation'] }}</td>
                    @else
                        <td colspan="3" class="text-muted">{{ exmtrans('storage_conversion.not_installed') }}</td>
                    @endif
                    <td>
                        @if($row['system_table'])
                            <span class="text-muted">{{ exmtrans('storage_conversion.system_table') }}</span>
                        @else
                            <a href="{{ admin_url('storage_conversion/' . $row['id']) }}">{{ exmtrans('storage_conversion.detail') }}</a>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<script>
// A row opens the detail of its table, like the Detail link (the link itself is clicked, so the admin's own
// navigation - PJAX - is used). Links and controls inside the row keep their own behaviour. Registered once.
(function () {
    if (window.exmStorageRowLink) {
        return;
    }
    window.exmStorageRowLink = true;
    var open = function (e) {
        var row = e.target.closest ? e.target.closest('tr.exm-storage-row') : null;
        if (!row || e.target.closest('a, button, input, select, textarea, label')) {
            return;
        }
        if (e.type === 'keydown' && e.key !== 'Enter') {
            return;
        }
        var link = row.querySelector('a[href]');
        if (link) {
            link.click();
        }
    };
    document.addEventListener('click', open);
    document.addEventListener('keydown', open);
})();
</script>
