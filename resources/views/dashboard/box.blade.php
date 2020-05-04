<div class="box box-dashboard {{isset($suuid) ? 'box-success' : ''}}" data-suuid="{{$suuid}}" {!! $attributes !!}>
    <div class="box-header with-border">
        <h3 class="box-title">
            @if(isset($title))
            {{ $title }}
            @else
            ({{exmtrans('dashboard.not_registered')}})
            @endif
        </h3>
        <div class="box-tools pull-right">
                @if(isset($suuid))
                @foreach($icons as $icon)
                @if(isset($icon['link']))
                <a class="btn btn-box-tool" href="{{$icon['link']}}" data-toggle="tooltip"  data-placement="left" title="{{$icon['tooltip']}}"><i class="fa {{$icon['icon']}}"></i></a>
                @else
                <button class="btn btn-box-tool" data-exment-widget="{{$icon['widget']}}" data-toggle="tooltip"  data-placement="left" title="{{$icon['tooltip']}}"><i class="fa {{$icon['icon']}}"></i></button>
                @endif
                @endforeach
                
                @else
                <div class="btn-group pull-right" style="margin-right: 5px">
                    @if(count($dashboardboxes_newbuttons) > 0)
                        <button type="button" class="btn btn-sm btn-success dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            <i class="fa fa-plus"></i><span class="hidden-xs">&nbsp;{{trans('admin.new')}}</span>
                            <span class="caret"></span>
                        </button>
                        <ul id="dashboard-menu" class="dropdown-menu">
                            @foreach($dashboardboxes_newbuttons as $button)
                            <li><a href="{{$button['url']}}"><i class="fa {{array_get($button, 'icon')}}"></i>&nbsp;{{array_get($button, 'view_name')}}</a></li>
                            @endforeach
                        </ul>
                    @endif
                </div>
                @endif
        </div><!-- /.box-tools -->
    </div><!-- /.box-header -->
    <div class="box-body" style="display: block;">
        <div class="box-body-inner">
            <div class="box-body-inner-header box-body-inneritem"></div>
            <div class="box-body-inner-body box-body-inneritem"></div>
            <div class="box-body-inner-footer box-body-inneritem"></div>
        </div>
    </div><!-- /.box-body -->

    @if(isset($suuid))
    <div class="overlay">
        <i class="fa fa-refresh fa-spin"></i>
    </div>
    @endif
</div>
