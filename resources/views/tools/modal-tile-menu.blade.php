<div class="modal-tile container-fluid">
    @foreach($groups as $group)
    <div class="row">
        @if(!is_null(array_get($group, 'header')))
        <h4>{{ array_get($group, 'header') }}</h4>
        @endif

        @foreach(array_get($group, 'items', []) as $item)
        <div class="col-sm-6 modal-tile-item">
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
                    <a href="{{array_get($button, 'href')}}" target="{{boolval(array_get($button, 'is_blank')) ? 'blank' : ''}}" class="btn btn-default btn-sm" {!! array_get($button, 'attributes') !!}>
                        <i class="fa {{array_get($button, 'icon')}}" aria-hidden="true"></i>&nbsp;{{array_get($button, 'label')}}
                    </a>
                    @endforeach
                </div>
            </div>
        </div>
        @endforeach
    </div>
    @endforeach
</div>


<style>
.modal-tile-item{
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 1.5em;
    height:100px;
}
.modal-tile-item-icon{
    display: flex;
    align-items: center;
    justify-content: center;
}
.modal-tile-item-icon button{
    width:50px;
    height:50px;
    padding: 10px 15px;
}
.modal-tile-item-icon .fa{
    font-size: 40px;
}
.modal-tile-item-icon button .fa{
    font-size: 20px;
}
.modal-tile-item-header{
    font-size:16px;
}
.modal-tile-item-description{
    font-size:12px;
}
.modal-tile-item .sub-buttons{
    margin-top:0.5em;
}
</style>