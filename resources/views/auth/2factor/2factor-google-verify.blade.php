@extends('exment::auth.layout') 
@section('content')
        <p class="login-box-msg">{{ exmtrans('2factor.2factor') }}</p>

        <form action="{{ admin_url('auth-2factor/verify') }}" method="post">
            <div class="form-group has-feedback {!! !$errors->has('verify_code') ?: 'has-error' !!}">
                <p>
                    {{ exmtrans('2factor.message.google.verify') }}
                </p>

                @if($errors->has('verify_code')) @foreach($errors->get('verify_code') as $message)
                <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i>{{$message}}</label></br>
                @endforeach @endif

                <input type="text" class="form-control" placeholder="{{ exmtrans('2factor.message.input_number') }}" name="verify_code" value="{{ old('verify_code') }}" required />
            </div>

            <div class="row">
                <div class="col-xs-12 col-sm-8 col-sm-offset-2">
                    <input type="hidden" name="_token" value="{{ csrf_token() }}">
                    <button type="submit" class="btn btn-primary btn-block btn-flat submit_disabled">{{ trans('admin.submit') }}</button>
                </div>
            </div>
        </form>

        <div style="margin:10px 0; text-align:center;">
            <p><a href="{{admin_url('auth-2factor/google/sendmail')}}">{{ exmtrans('2factor.message.google.resend') }}</a></p>
            <p><a href="{{admin_url('auth-2factor/logout')}}">{{ trans('admin.back') }}</a></p>
        </div>
        
<!-- /.login-box -->
@endsection