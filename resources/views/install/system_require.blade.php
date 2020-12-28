@extends('exment::install.layout') 
@section('content')
    <input type="hidden" id="has_warning_text" value="{{exmtrans('system_require.warning_confirm')}}">
    <p class="login-box-msg">{{ trans('admin.setting') }}(3/4) : {{exmtrans('install.system_require.header')}}</p>

    <p class="text-center">{{exmtrans('system_require.explain')}}</p>

    <div class="form-group has-feedback {!! !$errors->has('database_canconnection') ?: 'has-error' !!}">
        @if($errors->has('install_error')) @foreach($errors->get('install_error') as $message)
        <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i>{{$message}}</label></br>
        @endforeach @endif
    </div>

    @include('exment::widgets.system-require')
    
    <div class="container-fluid">
        <div class="row" style="margin-top:2em;">
            <div class="col-xs-3">
                <a href="{{admin_urls('install', 'reset')}}" class="btn btn-default btn-block btn-flat click_disabled">{{trans('admin.reset')}}</a>
            </div>
            
            <div class="col-xs-3">
                <form action="{{ admin_url('install') }}" method="post">
                <input type="hidden" name="_token" value="{{ csrf_token() }}">
                <input type="hidden" name="refresh" value="1">
                <button type="submit" class="btn btn-default btn-block btn-flat click_disabled">{{ trans('admin.refresh') }}</button>
                </form>
            </div>
            <div class="col-xs-6">
                <form action="{{ admin_url('install') }}" method="post" id="form_next" class="check_has_warning">
                <input type="hidden" name="_token" value="{{ csrf_token() }}">
                <input type="hidden" id="has_warning" value="{{$checkResult->hasResultWarning()}}">
                <button type="submit" class="btn btn-primary btn-block btn-flat" {{$checkResult->hasResultNg() ? 'disabled' : ''}}>
                    {{ trans('admin.next') }}
                </button>
                </form>
            </div>
        </div>
    </div>
@endsection
