@extends('exment::auth.layout') 

@section('content')
<style>
img{
    display:block;
    margin-left:auto;
    margin-right:auto;
    max-width:80%;
}

.register_step_block{
    margin:3em 0;
}
</style>

        <p class="login-box-msg">{{ exmtrans('2factor.2factor') }}</p>

        <form action="{{ admin_url('auth-2factor/verify') }}" method="post">
            <p>
                {{ exmtrans('2factor.message.google.register_first') }}
            </p>

            <div class="register_step_block">
                <label>
                    Step1. {{ exmtrans('2factor.google.register_download') }}
                </label>
                <p>{{ exmtrans('2factor.message.google.register_download') }}</p>
                <a href="{{ $urlAndroid }}" target="_blank"><img src="{{ admin_asset('images/2factor/google-play-badge.png')}}" /></a>
                <img src="data:image/png;base64, {{ $qrSrcAndroid }}" style="display:block; margin:-1em auto 0;">

                <a href="{{ $urlIphone }}" target="_blank"><img src="{{ admin_asset('images/2factor/apple-badge.svg')}}" style="width:70%; margin-top:2em;" /></a>
                <img src="data:image/png;base64, {{ $qrSrcIphone }}" style="display:block; margin: auto;">
            </div>

            <div class="register_step_block">
                <label>
                    Step2. {{ exmtrans('2factor.google.add_acount') }}
                </label>
                <p>{{ exmtrans('2factor.message.google.add_acount') }}</p>
                <img src="data:image/png;base64, {{ $qrSrc }}" style="display:block; margin:auto;">
            </div>
            
            <div class="register_step_block form-group has-feedback {!! !$errors->has('verify_code') ?: 'has-error' !!}">
                <label>
                    Step3. {{ exmtrans('2factor.google.input_verify_code') }}
                </label>
                <p>{{ exmtrans('2factor.message.google.input_verify_code') }}</p>

                @if($errors->has('verify_code')) @foreach($errors->get('verify_code') as $message)
                <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i>{{$message}}</label></br>
                @endforeach @endif

                <input type="text" class="form-control" placeholder="{{ exmtrans('2factor.message.input_number') }}" name="verify_code" value="{{ old('verify_code') }}" required />
            </div>

            <div class="row">
                <div class="col-xs-12 col-sm-8 col-sm-offset-2">
                    <input type="hidden" name="code" value="{{ $code }}">
                    <input type="hidden" name="_token" value="{{ csrf_token() }}">
                    <button type="submit" class="btn btn-primary btn-block btn-flat submit_disabled">{{ trans('admin.submit') }}</button>
                </div>
            </div>
        </form>

        <div style="margin:10px 0; text-align:center;">
            <p><a href="{{admin_url('auth-2factor/logout')}}">{{ trans('admin.back') }}</a></p>
        </div>
        
<!-- /.login-box -->
@endsection