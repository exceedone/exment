@foreach($options as $option)
<div class="col-xs-12 col-sm-6 tile-group-item">
    <div id="tile-{{$column}}-{{$loop->index}}" class="tile" data-id="{{array_get($option, 'id')}}">
        @if(!is_null(array_get($option, 'thumbnail')))
        <div class="tile-thumbnail">
            <img src="{{ array_get($option, 'thumbnail') }}" />
        </div>
        @endif
        <div class="tile-titledescription {{ !is_null(array_get($option, 'thumbnail')) ? 'hasThumbnail' : '' }}">
            <div class="clearfix">
                <p class="pull-left tile-title">{{ array_get($option, 'title') }}</p>
                @if(!is_null(array_get($option, 'delete_url')))
                <p class="pull-right" style="margin:0;">
                    <button type="button" class="btn btn-box-tool" data-exment-delete="{{array_get($option, 'delete_url')}}" style="color:#333;"><i class="fa fa-trash"></i></button>
                </p>
                @endif
            </div>
            @if(!is_null(array_get($option, 'description')))
            <p class="tile-description">{{ array_get($option, 'description') }}</p>
            @endif
            @if(!is_null(array_get($option, 'author')))
            <p class="tile-description">
                @if(!is_null(array_get($option, 'author_url')))
                    <a href="{{array_get($option, 'author_url')}}" target="_blank">
                        {{ array_get($option, 'author') }}
                    </a>
                @else
                    {{ array_get($option, 'author') }}
                @endif
            </p>
            @endif
        </div>
        <input type="hidden" class="tile-value" name="{{$name}}[]" />
    </div>
</div>
@endforeach

@if(isset($paginator))
<div class="col-xs-12 col-sm-12 text-center">
{{ $paginator->links('exment::search.links') }}
</div>
@endif
