<div class="box box-dashboard {{isset($suuid) ? 'box-success' : ''}}" data-suuid="{{$suuid}}">
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
                <a class="btn btn-box-tool" href="{{admin_base_path('dashboardbox/'.$id.'/edit')}}"><i class="fa fa-cog"></i></a>
                <button class="btn btn-box-tool" data-exment-widget="delete"><i class="fa fa-trash"></i></button>
                @else
                <div class="btn-group pull-right" style="margin-right: 5px">
                        <button type="button" class="btn btn-sm btn-success dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            <i class="fa fa-save"></i>&nbsp;{{trans('admin.new')}}
                            <span class="caret"></span>
                        </button>
                        <ul id="dashboard-menu" class="dropdown-menu">
                            @foreach($dashboardboxes_newbuttons as $button)
                            <li><a href="{{$button['url']}}"><i class="fa {{array_get($button, 'icon')}}"></i>&nbsp;{{array_get($button, 'view_name')}}</a></li>
                            @endforeach
                        </ul>
                </div>
                @endif
        </div><!-- /.box-tools -->
    </div><!-- /.box-header -->
    <div class="box-body" style="display: block;">
        <div class="box-body-inner"></div>
    </div><!-- /.box-body -->

    @if(isset($suuid))
    <div class="overlay">
        <i class="fa fa-refresh fa-spin"></i>
    </div>
    @endif
</div>


{{-- TODO:scssに記載 --}}
<style type="text/css">
    .row{
        display: -webkit-box;
        display: -webkit-flex;
        display: -ms-flexbox;
        display: flex;
    }
    
    [class^="col-"] {
        margin-bottom: 20px;
    }

    .box{
        height:100%;
        margin-bottom: 0;
    }

    .box-dashboard .box-body{
        min-height:150px;
    }
    .box-dashboard .box-body .box-body-inner{
        overflow-x: auto;
    }
    .box-dashboard table td, .box-dashboard table th, {
        white-space: nowrap;
    }
</style>