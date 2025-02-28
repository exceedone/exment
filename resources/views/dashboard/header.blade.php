<div class="box box-info custom-border-info card">
    <div class="box-header with-border p-2 d-flex justify-content-end">
        <div class="btn-group pb-1 pt-1" style="margin-right: 5px">
            <button type="button" class="btn btn-sm btn-default dropdown-toggle" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                <i class="fa fa-window-maximize"></i>&nbsp;{{exmtrans('dashboard.header')}}&nbsp;:&nbsp;{{ $current_dashboard->dashboard_view_name }}
                <span class="caret"></span>
            </button>
            <ul id="dashboard-menu" class="dropdown-menu">
                    @if(count($systemdashboards) > 0)
                        <li class="dropdown-header d-flex justify-content-start">{{exmtrans('dashboard.dashboard_type_options.system')}}</li>
                        @foreach($systemdashboards as $dashboard)
                            <li><a href="{{$base_uri}}?dashboard={{$dashboard['suuid']}}">&nbsp;{{array_get($dashboard, 'dashboard_view_name')}}</a></li>
                        @endforeach
                    @endif
                    @if(count($userdashboards) > 0)
                        <li class="dropdown-header d-flex justify-content-start">{{exmtrans('dashboard.dashboard_type_options.user')}}</li>
                        @foreach($userdashboards as $dashboard)
                            <li><a href="{{$base_uri}}?dashboard={{$dashboard['suuid']}}">&nbsp;{{array_get($dashboard, 'dashboard_view_name')}}</a></li>
                        @endforeach
                    @endif
    
                    @if(count($settings) > 0)
                        <li class="dropdown-header d-flex justify-content-start">{{trans('admin.setting')}}</li>
                        @foreach($settings as $dashboard)
                            <li><a href="{{$dashboard['url']}}">&nbsp;{{array_get($dashboard, 'dashboard_view_name')}}</a></li>
                        @endforeach
                    @endif
            </ul>
        </div>
    </div>
</div>