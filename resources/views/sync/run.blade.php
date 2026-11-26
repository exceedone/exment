{{-- A run of the data sync (ISS00113): SyncController@showRun --}}
<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">
            {{ isset($mapping) ? $mapping->name : '' }}
            ・ {{ exmtrans('sync.status.' . $run->status) }}
            @if($run->dry_run) ({{ exmtrans('sync.dry_run') }}) @endif
        </h3>
    </div>
    <div class="box-body">
        <p class="text-muted">
            {{ optional($run->started_at)->format('Y-m-d H:i:s') }} 〜 {{ optional($run->finished_at)->format('Y-m-d H:i:s') }}
            ・ {{ exmtrans('sync.trigger.' . $run->trigger) }}
        </p>
        @if($run->status === \Exceedone\Exment\Model\SyncRun::STATUS_RUNNING)
            <p>{{ exmtrans('sync.running_help') }}</p>
        @endif
        @if(!is_nullorempty($run->message))
            <div class="alert alert-danger">{{ $run->message }}</div>
        @endif
        <table class="table table-condensed" style="max-width: 40em;">
            <tbody>
            @foreach(\Exceedone\Exment\Model\SyncRun::COUNTS as $name)
                <tr><th>{{ exmtrans('sync.count.' . $name) }}</th><td>{{ $run->count($name) }}</td></tr>
            @endforeach
            </tbody>
        </table>
        @if(count($runErrors) > 0)
            <h4>{{ exmtrans('sync.errors') }}</h4>
            <table class="table table-condensed">
                <thead><tr><th>{{ exmtrans('sync.remote_id') }}</th><th>{{ exmtrans('sync.reason') }}</th></tr></thead>
                <tbody>
                @foreach($runErrors as $error)
                    <tr><td>{{ $error->remote_id }}</td><td>{{ $error->message }}</td></tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
    <div class="box-footer">
        <a class="btn btn-default" href="{{ admin_url('sync') }}">{{ trans('admin.back') }}</a>
    </div>
</div>
