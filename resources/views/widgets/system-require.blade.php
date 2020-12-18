
<div class="container-fluid">
    <div class="row row-eq-height">
        @foreach($checkResult->getItems() as $check)
        <div class="col-xs-12 col-sm-6 require_item">
            <h4 class="require_item_header corsor-pointer" data-toggle="collapse" data-target="#collapse{{$loop->index}}">
                <i class="fa {{$check->getResultClassSet()['fontawesome']}}" aria-hidden="true" style="color: {{$check->getResultClassSet()['color']}}"></i>
                {{$check->getLabel()}}
            </h4>

            <div class="collapse {{$check->checkResult() != 'ok' ? 'in' : ''}}" id="collapse{{$loop->index}}">
                <p>
                    {{$check->getExplain()}}
                </p>
                <p>
                    {{exmtrans('common.result')}} : <span class="bold">{{$check->getResultText()}}</span>
                </p>

                @if(!is_null($check->getMessage()))
                <p class="red">
                    {{$check->getMessage()}}
                </p>
                @endif
                
                <p>
                    <a href="{{$check->getSettingUrl()}}" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-primary">
                        {{ exmtrans('system_require.check_setting') }}
                    </a>
                </p>
            </div>
        </div>
        @endforeach
    </div>
</div>
