<div class="modal-tile container-fluid">
    @foreach($groups as $group)
    <div class="row">
        @if(!is_null(array_get($group, 'header')))
        <h4 class="col-sm-12 groupheader">
            <i class="fa fa-check" aria-hidden="true"></i>
            {{ array_get($group, 'header') }}
            </h4>
        @endif

        @foreach(array_get($group, 'items', []) as $item)
        <div class="col-sm-12 col-md-6 modal-tile-col">
            <div class="modal-tile-item">
                <div class="col-sm-3 modal-tile-item-icon">
                    @if(!is_nullorempty(array_get($item, 'href')))
                        <a href="{{array_get($item, 'href')}}" class="btn btn-default">
                            <i class="fa {{array_get($item, 'icon')}}" aria-hidden="true"></i>
                        </a>
                    @else
                        <i class="fa {{array_get($item, 'icon')}}" aria-hidden="true"></i>
                    @endif
                </div>
                <div class="col-sm-9">
                    <h4 class="modal-tile-item-header">{{array_get($item, 'header')}}</h4>
                    <div class="modal-tile-item-description">
                        {{array_get($item, 'description')}}
                    </div>
                    <div class="sub-buttons">
                        @foreach(array_get($item, 'buttons', []) as $button)
                        <a href="{{array_get($button, 'href')}}" class="btn btn-default btn-sm" {!! array_get($button, 'attributes') !!}>
                            <i class="fa {{array_get($button, 'icon')}}" aria-hidden="true"></i>&nbsp;{{array_get($button, 'label')}}
                        </a>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
        @endforeach
    </div>
    @endforeach
</div>

