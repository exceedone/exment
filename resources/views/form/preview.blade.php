{{--
    Read-only rendering of a form that has not been saved.
    Deliberately plain: the point is to read the content, not to rehearse the layout.
--}}
<div class="box box-warning">
    <div class="box-header with-border">
        <h3 class="box-title">
            <i class="fa fa-eye"></i>&nbsp;{{ exmtrans('common.preview') }}
            <small style="margin-left:8px">{{ $table_view_name }}</small>
        </h3>
    </div>
    <div class="box-body">
        <div class="callout callout-warning" style="margin-bottom:16px">
            {{ exmtrans('common.message.preview') }}
        </div>

        @foreach($groups as $group)
            @if(!empty($group['title']))
                <h4>{{ $group['title'] }}</h4>
            @endif
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <tbody>
                    @foreach($group['rows'] as $row)
                        <tr>
                            <th style="width:30%;vertical-align:middle">{{ $row['label'] }}</th>
                            <td>{!! $row['html'] !!}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach
    </div>
</div>
