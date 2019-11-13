<div class="btn-group pull-right" style="margin-right: 5px">
        <button type="button" class="btn btn-sm btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
            <i class="fa fa-th-list"></i>&nbsp;<span class="hidden-xs">{{exmtrans('custom_view.custom_view_button_label')}}&nbsp;:&nbsp;{{ $current_custom_view->view_view_name }}&nbsp;</span>
            <span class="caret"></span>
        </button>
        <ul id="custom-view-menu" class="dropdown-menu">
                @if(count($systemviews) > 0)
                    <li class="dropdown-header">{{exmtrans('custom_view.custom_view_type_options.system')}}</li>
                    @foreach($systemviews as $view)
                        <li><a href="{{$base_uri}}?view={{$view['suuid']}}">&nbsp;{{array_get($view, 'view_view_name')}}</a></li>
                    @endforeach
                @endif
                @if(count($userviews) > 0)
                    <li class="dropdown-header">{{exmtrans('custom_view.custom_view_type_options.user')}}</li>
                    @foreach($userviews as $view)
                        <li><a href="{{$base_uri}}?view={{$view['suuid']}}">&nbsp;{{array_get($view, 'view_view_name')}}</a></li>
                    @endforeach
                @endif

                @if(count($settings) > 0)
                    <li class="dropdown-header">{{trans('admin.setting')}}</li>
                    @foreach($settings as $view)
                        <li><a href="{{$view['url']}}">&nbsp;{{array_get($view, 'view_view_name')}}</a></li>
                    @endforeach
                @endif
        </ul>
    </div>