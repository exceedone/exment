
@if($hasError)
<div class="has-error form-group">
    <div class="col-sm-offset-2 col-sm-8 red">
        {{exmtrans('error.description')}}
    </div>
</div>
@endif
<style>
    div.table-container {
        border-collapse: collapse;
    }
    .border-solid {
        border: 1px solid #AAAAAA;
    }
    .table-section{ display: table; }
    .table-row { display: table-row; }
    .table-heading { display: table-header-group;}
    .table-cell, .table-head { display: table-cell; height: 50px;}
    .table-heading { display: table-header-group;}
    .table-foot { display: table-footer-group;}
    .table-body { display: table-row-group;}
    .table-label { margin-top: 5px; padding-top: 0;margin-right: 5px}
    .table-right {
        padding-right: 0;
        z-index: 10;
        left: 1px;
    }
    .table-left {
        padding-left: 0;
    }
</style>

<div class="checkboxtable form-group">
    <div class="{{$viewClass['label']}} table-right" style="{{ $scrollx ? 'margin-top: 20px' : '' }}">
    
        <div class="checkboxtable-header"></div>
        <div class="table-section table-container w-100 border-solid">
            <div class="table-body">
                @foreach($items as $item)
                <div class="table-row w-100">
                    <div class="table-cell border-solid">
                        <div class="checkboxtable-body text-right {{!is_nullorempty(array_get($item, 'error')) ? 'has-error' : ''}}">
                            <label for="{{$id}}" class="control-label table-label">{{$item['label']}}</label>
                    
                            @if(!is_nullorempty(array_get($item, 'error')))
                            <i class="fa fa-exclamation-circle" data-help-text="{{array_get($item, 'error')}}" data-help-title="{{ exmtrans('common.error') }}"></i>
                            @endif
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </div>
    @if($scrollx)
        <div class="wrapper-scroll-top col-md-10"><div class="wrapper-scroll-top-content"></div></div>
    @endif
    <div class="{{$viewClass['field']}} table-left {{ $scrollx ? 'wrapper-scroll-bottom' : '' }}" style="overflow-y:hidden; overflow-x:auto; white-space: nowrap;">
        <div class="checkboxtable-header">
            <div class="table-section table-container border-solid">
                <div class="table-body">
                    <div class="table-row">
                        @foreach($options as $option => $label)
                        <div class="table-cell border-solid">
                            <span style="width:{{$checkWidth}}px; display:inline-block; text-align:center; font-size:0.85em;">
                                @if($headerEsacape)
                                {{$label}}
                                @else
                                {!! $label !!}
                                @endif
                    
                                @if(!empty($headerHelps[$option]))
                                <br/>
                                <i class="fa fa-info-circle" data-help-text="{{$headerHelps[$option]}}" data-help-title="{{ $label }}"></i>
                                @endif
                            </span>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    
        <div class="table-section table-container border-solid">
            <div class="table-body">
                @foreach($items as $item)
                <div class="table-row">
                    @foreach($options as $option => $label)
                    <div class="table-cell border-solid">
                        <span class="icheck" style="width:{{$checkWidth}}px; display:inline-block; text-align:center;">
                            <label class="checkbox-inline" style="margin-top:5px">
                                <input type="checkbox" name="{{$item['name']}}[]" value="{{$option}}" class="{{$class}}" {{ in_array($option, (array)old(array_get($item, 'key'), $item['values'])) || ($item['values'] === null && in_array($label, $checked)) ?'checked':'' }} 
                                    {{ in_array($option, array_get($item, 'disables', [])) ? ' disabled' : '' }}  {!! $attributes !!} 
                                />
                            </label>
                        </span>
                    </div>
                    @endforeach
    
                    <input type="hidden" name="{{$item['name']}}[]">
    
                    @include('admin::form.help-block')
                </div>
                @endforeach
            </div>
        </div>
    
    </div>

</div>
@if($scrollx)
    <style>
        .wrapper-scroll-top{
            overflow-x: auto;
            overflow-y: hidden;
            white-space: nowrap;
            height: 20px;
        }
        .wrapper-scroll-top-content{
            width: 1950px;
            height: 20px;
        }
    </style>
    <script>
        $(function () {
            $(window).off("exment:loaded", changeText).on("exment:loaded", changeText);
            function changeText() {
                $(function(){
                    $(".wrapper-scroll-top").scroll(function(){
                        $(".wrapper-scroll-bottom")
                            .scrollLeft($(".wrapper-scroll-top").scrollLeft());
                    });
                    $(".wrapper-scroll-bottom").scroll(function(){
                        $(".wrapper-scroll-top")
                            .scrollLeft($(".wrapper-scroll-bottom").scrollLeft());
                    });
                });
            }
        });
    </script>
@endif