<div class="box box-dashboard custom-border-success card p-2 {{isset($suuid) ? 'box-success' : ''}}" data-suuid="{{$suuid}}" {!! $attributes !!}>
    <div class="box-header with-border  d-flex justify-content-between  border-bottom">
        <h3 class="box-title fs-4">
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
                <a class="btn btn-outline-secondary btn-outline-secondary-active btn-sm" href="{{$icon['link']}}" data-bs-toggle="tooltip"  data-placement="left" title="{{$icon['tooltip']}}"><i class="fa {{$icon['icon']}} text-secondary"></i></a>
                @else
                <button class="btn btn-outline-secondary btn-outline-secondary-active btn-sm" data-exment-widget="{{$icon['widget']}}" data-bs-toggle="tooltip"  data-placement="left" title="{{$icon['tooltip']}}"><i class="fa {{$icon['icon']}} text-secondary"></i></button>
                @endif
                @endforeach
                
                @else
                <div class="btn-group pull-right" style="margin-right: 5px">
                    @if(count($dashboardboxes_newbuttons) > 0)
                        <button type="button" class="btn btn-sm btn-success  dropdown-toggle" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
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
        <div class="box-body-inner-header box-body-inneritem d-flex justify-content-end pt-3"></div>
        <div class="box-body-inner-body box-body-inneritem"></div>
        <div class="box-body-inner-footer box-body-inneritem"></div>
    </div>
</div>
<!-- /.box-body -->

    @if(isset($suuid))
    <div class="overlay">
        <i class="fa fa-refresh fa-spin"></i>
    </div>
    @endif
</div>
