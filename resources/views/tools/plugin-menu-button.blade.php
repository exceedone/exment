{{-- grid_tool: inside the data-grid toolbar the button joins the normal flow
     (.exm-grid-tool spacing, DOM order = display order) instead of floating. --}}
<a id="menu_button_{{$uuid}}" class="btn btn-sm {{$button_class}} {{ !empty($grid_tool) ? 'exm-grid-tool' : 'pull-right' }} p-2"@if(empty($grid_tool)) style="margin-right:5px;"@endif>
    <i class="fa {{$icon}}"></i>
    <span class="d-none d-md-inline">&nbsp;{{$label}}</span>
</a>