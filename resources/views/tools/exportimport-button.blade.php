<div class="btn-group pull-right" style="margin-right: 5px">
    <button type="button" class="btn btn-sm btn-twitter dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
        <i class="fa fa-download"></i> {{exmtrans('custom_value.import_export')}}
        <span class="caret"></span>
    </button>
    <ul class="dropdown-menu">
        @foreach($buttons as $button_key => $button_value)
            @foreach($button_value['menulist'] as $menu)
                <li class="dropdown-header">{{$menu['label']}}({{ $button_value['format_text'] }})</li>
                @foreach($menu['items'] as $item)
                    <li>
                        {{-- output a tag --}}
                        <a 
                        {{-- loop a attribute --}}
                        @foreach($item as $attr_key => $attr_value)
                            {{-- loop if text, continue --}}
                            @if($attr_key == 'text')
                            @continue
                            @elseif($attr_key == 'href' && array_get($item, 'format_query', true) === true)
                            href="{{$attr_value}}&format={{$button_key}}"
                            @continue
                            @elseif($attr_key == 'href')
                            href="{{$attr_value}}"
                            @continue
                            @endif

                            {{-- set attribbute --}}
                            {{$attr_key}}="{{$attr_value}}"
                        @endforeach
                        >
                        {{$item['text']}}
                        </a>
                    </li>
                @endforeach {{--// $menu['items'] --}}
            @endforeach {{--// $button_value['menulist'] --}}
                
            {{-- if not last, add divider --}}
            @if(!$loop->last)
            <li role="presentation" class="divider"></li>
            @endif
        @endforeach {{--// buttons --}}

    </ul>
</div>
&nbsp;&nbsp;