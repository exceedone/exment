{{-- grid_tool: inside the data-grid toolbar the button joins the normal flow
     (.exm-grid-tool spacing, DOM order = display order); everywhere else it
     keeps the classic right float. --}}
<div class="btn-group {{ !empty($grid_tool) ? 'exm-grid-tool' : 'float-end' }}"@if(empty($grid_tool)) style="margin-right: 5px"@endif>
    <a href="{{admin_url('data/'.$table_name.'/create'). ( isset($params) ? '?' . http_build_query($params) : '' ) }}" class="btn btn-sm btn-success d-flex align-items-center">
        <i class="fa fa-plus p-1"></i><span class="d-none d-lg-block">&nbsp;&nbsp;{{trans('admin.new')}}</span>
    </a>
</div>