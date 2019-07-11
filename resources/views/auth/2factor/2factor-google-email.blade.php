@extends('exment::auth.layout') 
@section('content')
        <p class="login-box-msg">{{ exmtrans('2factor.2factor') }}</p>

        <form action="{{ admin_url('auth-2factor/google/sendmail') }}" method="get">
            <div class="form-group has-feedback {!! !$errors->has('verify_code') ?: 'has-error' !!}">
                <p>
                    {!! $message_available !!}
                </p>
            </div>
            
            <div class="row">
                <div class="col-xs-12 col-sm-8 col-sm-offset-2">
                    <button type="submit" class="btn btn-primary btn-block btn-flat submit_disabled">{{ trans('admin.submit') }}</button>
                </div>
            </div>
        </form>

        <div style="margin:10px 0; text-align:center;">
            <p><a href="{{admin_url('auth-2factor/logout')}}">{{ trans('admin.back') }}</a></p>
        </div>
        
<!-- /.login-box -->
@endsection