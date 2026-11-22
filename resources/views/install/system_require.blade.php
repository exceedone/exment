@extends('exment::install.layout') 
@section('content')
    <input type="hidden" id="has_warning_text" value="{{exmtrans('system_require.warning_confirm')}}">
    <p class="login-box-msg text-center">{{ trans('admin.setting') }}(3/4) : {{exmtrans('install.system_require.header')}}</p>

    <p class="text-center">{{exmtrans('system_require.explain')}}</p>

    <div class="form-group text-danger {!! !$errors->has('database_canconnection') ?: 'has-error' !!}">
        @if($errors->has('install_error')) @foreach($errors->get('install_error') as $message)
        <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i>{{$message}}</label></br>
        @endforeach @endif
    </div>

    @include('exment::widgets.system-require')

    @isset($tuningResult)
    @include('exment::widgets.tuning-advice')
    @endisset

    {{-- the security check (ISS02833): the server's items to fix; the fixes are on its page after the install --}}
    @if(!empty($securityProblems))
    <div class="container-fluid security_advice">
        <h4 style="margin-top:1.5em;">{{ exmtrans('security_check.install_header') }}</h4>
        <p class="small">{{ exmtrans('security_check.install_explain') }}</p>
        <div class="row row-eq-height">
            @foreach($securityProblems as $item)
            <div class="col-xs-12 col-sm-6 require_item">
                <h5 class="bold">
                    <i class="fa fa-shield" aria-hidden="true" style="color: #d9534f"></i>
                    {{ $item->getLabel() }} : {{ $item->getResultText() }}
                </h5>
                <p class="small">{{ $item->getMessage() }}</p>
                @if(!is_null($fixText = $item->getFixText()))
                <pre class="small" style="white-space: pre-wrap;">{{ $fixText }}</pre>
                @endif
            </div>
            @endforeach
        </div>
    </div>
    @endif
    
    <div class="container-fluid">
        <div class="row" style="margin-top:2em;">
            <div class="col-xs-12 col-sm-3">
                <a href="{{admin_urls('install', 'reset')}}" class="btn btn-default btn-block w-100 click_disabled">{{trans('admin.reset')}}</a>
            </div>
            
            <div class="col-xs-12 col-sm-3">
                <form action="{{ admin_url('install') }}" method="post">
                <input type="hidden" name="_token" value="{{ csrf_token() }}">
                <input type="hidden" name="refresh" value="1">
                <button type="submit" class="btn btn-default btn-block w-100 click_disabled">{{ trans('admin.refresh') }}</button>
                </form>
            </div>
            
            <div class="col-xs-12 col-sm-6">
                <form action="{{ admin_url('install') }}" method="post" id="form_next" class="check_has_warning">
                <input type="hidden" name="_token" value="{{ csrf_token() }}">
                <input type="hidden" id="has_warning" value="{{$checkResult->hasResultWarning()}}">
                <button style="background-color: #3c8dbc;border-color: #367fa9;" type="submit" class="btn btn-primary btn-block w-100" {{$checkResult->hasResultNg() ? 'disabled' : ''}}>
                    {{ trans('admin.next') }}
                </button>
                </form>
            </div>
        </div>
    </div>
@endsection
