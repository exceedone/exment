
<div style="position:relative; width:100%; height: calc(100% - {{count($items) * 65 + 5}}px); border: 1px solid black;">
    <iframe src="{{$iframe_url}}" sandbox="allow-same-origin allow-popups allow-scripts allow-forms" class="selectitembox-body" style="position:absolute; left:0; top:0; width:100%; height: 100%; border:none; overflow-y:auto; overflow-x:hidden;">
    </iframe>
    <div class="selectitembox-loading" style="position:absolute; left:0; top:0; width:100%; height: 100%; z-index:10000; background-color:white;">
        <i class="fa fa-spinner fa-spin fa-4x" style="position:absolute; left:calc(50% - 0.5em); top:calc(50% - 0.5em);"></i>
    </div>
</div>


<div class="selectitembox-footer" style="">
    @foreach($items as $item)
    <div class="selectitembox-item" style="height:60px; margin:10px 0;" 
        data-selectitem="{{array_get($item, 'name')}}"  
        data-multiple="{{boolval(array_get($item, 'multiple'))}}" 
        data-selectitem-icon="{{array_get($item, 'icon')}}" 
        data-selectitem-color="{{array_get($item, 'color', '#FFFFFF')}}" 
        data-selectitem-background_color="{{array_get($item, 'background_color', '#3c8dbc')}}"
        data-selectitem-target_class="{{$target_class}}"
        data-selectitem-widgetmodal_uuid="{{$widgetmodal_uuid}}"
    >

        <div class="col-sm-1 text-right">{{array_get($item, 'label')}}</div>
        <div class="col-sm-11">
            <div style="border:1px solid #d2d6de; height:60px; overflow-y: auto;" class="selectitembox-item-inner">
                @foreach(array_get($item, 'items', []) as $i)
                <span class="selectitembox-value" style="position: relative; display: inline-block; margin: 3px 2px; padding: 1px 10px; border-radius: 4px; background-color: {{array_get($i, 'background_color', array_get($item, 'background_color', '#3c8dbc'))}};  color: {{array_get($i, 'color', array_get($item, 'color', '#FFFFFF'))}}">
                    @if(array_has($i, 'icon') || array_has($item, 'icon'))
                        <i class="fa {{array_get($i, 'icon', array_get($item, 'icon'))}}"></i>
                    @endif
                    
                    <span class="selectitem-label">
                    {{array_get($i, 'label')}}
                    </span>

                    <input type="hidden" class="selectitem-value" value="{{array_get($i, 'value')}}" />

                    <i class="fa fa-times button-delete" style="cursor: pointer; margin-left: 5px;"></i>
                </span>
                @endforeach
            </div>
        </div>

        <template>
        <span class="selectitembox-value" style="position: relative; display: inline-block; margin: 3px 2px; padding: 1px 10px; border-radius: 4px; background-color: {{array_get($item, 'background_color', '#3c8dbc')}};  color: {{array_get($item, 'color', '#FFFFFF')}}">
            <i class="fa {{array_get($item, 'icon')}}"></i>

            <span class="selectitem-label">%label%</span>

            <input type="hidden" class="selectitem-value" value="%value%" />

            <i class="fa fa-times button-delete" style="cursor: pointer; margin-left: 5px;"></i>
        </span>
        </template>
    </div>
    @endforeach
</div>

<script>
$('.selectitembox-body').load(function () {
    $('.selectitembox-loading').fadeOut(200);
});
</script>