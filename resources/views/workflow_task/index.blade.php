<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">
            {{ exmtrans('workflow_task.header') }}
            <small>
                {{ exmtrans('workflow_task.count', $total) }}
                @if($unseenTotal > 0)
                    / <span class="label label-danger">{{ exmtrans('workflow_task.unseen_count', $unseenTotal) }}</span>
                @endif
            </small>
        </h3>
        @if($unseenTotal > 0)
            <div class="box-tools pull-right">
                <form method="post" action="{{ admin_url('workflow_task/readAll') }}" style="display:inline;">
                    {!! csrf_field() !!}
                    <button type="submit" class="btn btn-sm btn-default">
                        <i class="fa fa-check"></i> {{ exmtrans('workflow_task.mark_all_seen') }}
                    </button>
                </form>
            </div>
        @endif
    </div>
    <div class="box-body table-responsive no-padding">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th style="width: 70px;">{{ exmtrans('workflow_task.seen_flg') }}</th>
                    <th>{{ exmtrans('workflow_task.table') }}</th>
                    <th>{{ exmtrans('workflow_task.data') }}</th>
                    <th>{{ exmtrans('workflow_task.status') }}</th>
                    <th>{{ exmtrans('workflow_task.updated_at') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $row)
                    <tr @if(!$row['seen']) style="font-weight: bold;" @endif>
                        <td>
                            @if($row['seen'])
                                <span class="label label-default">{{ exmtrans('workflow_task.seen_options.1') }}</span>
                            @else
                                <span class="label label-danger">{{ exmtrans('workflow_task.seen_options.0') }}</span>
                            @endif
                        </td>
                        <td>{{ $row['table_view_name'] }}</td>
                        <td><a href="{{ admin_url('workflow_task/read') }}?key={{ urlencode($row['task_key']) }}">{{ $row['label'] }}</a></td>
                        <td>{!! $row['status_tag'] !!}</td>
                        <td>{{ $row['updated_at'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center">{{ exmtrans('workflow_task.empty') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($total > 0)
        <div class="box-footer clearfix">
            <div class="pull-left" style="margin-top: 7px;">
                {!! trans('admin.pagination.range', [
                    'first' => '<b>' . ($paginator->firstItem() ?? 0) . '</b>',
                    'last'  => '<b>' . ($paginator->lastItem() ?? 0) . '</b>',
                    'total' => '<b>' . $paginator->total() . '</b>',
                ]) !!}
            </div>

            <label class="control-label pull-right" style="margin-right: 10px; font-weight: 100;">
                <small>{{ trans('admin.show') }}</small>&nbsp;
                <select class="input-sm" onchange="location.href = this.value;">
                    @foreach($perPageOptions as $opt)
                        <option value="{{ request()->fullUrlWithQuery([$perPageName => $opt, 'page' => 1]) }}" {{ $opt == $perPage ? 'selected' : '' }}>{{ $opt }}</option>
                    @endforeach
                </select>
                &nbsp;<small>{{ trans('admin.entries') }}</small>
            </label>

            <div class="pull-right" style="margin-right: 10px;">
                {!! $paginator->render('admin::pagination') !!}
            </div>
        </div>
    @endif
</div>
