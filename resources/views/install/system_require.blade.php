@extends('exment::install.layout') 
@section('content')
    <input type="hidden" id="has_warning_text" value="{{exmtrans('system_require.warning_confirm')}}">
    <p class="login-box-msg">{{ trans('admin.setting') }}(3/4) : {{exmtrans('install.system_require.header')}}</p>

    <p class="text-center">{{exmtrans('system_require.explain')}}</p>

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

        <div class="row" style="margin-top:2em;">
            <div class="col-xs-6">
                <form action="{{ admin_url('install') }}" method="post">
                <input type="hidden" name="_token" value="{{ csrf_token() }}">
                <input type="hidden" name="refresh" value="1">
                <button type="submit" class="btn btn-default btn-block btn-flat btn-install-next">{{ trans('admin.refresh') }}</button>
                </form>
            </div>
            <div class="col-xs-6">
                <form action="{{ admin_url('install') }}" method="post" id="form_next" class="check_has_warning">
                <input type="hidden" name="_token" value="{{ csrf_token() }}">
                <input type="hidden" id="has_warning" value="{{$checkResult->hasResultWarning()}}">
                <button type="submit" class="btn btn-primary btn-block btn-flat btn-install-next" {{$checkResult->hasResultNg() ? 'disabled' : ''}}>
                    {{ trans('admin.next') }}
                </button>
                </form>
            </div>
        </div>
    </div>
@endsection
