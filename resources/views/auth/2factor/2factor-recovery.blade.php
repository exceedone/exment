@extends('exment::auth.layout')
@section('content')
        <p class="login-box-msg">{{ exmtrans('2factor.recovery_login') }}</p>

        @if($has_codes)
        <form action="{{ admin_url('auth-2factor/recovery/verify') }}" method="post">
            <div class="form-group mb-3 has-feedback {!! !$errors->has('recovery_code') ?: 'has-error' !!}">
                <p>
                    {{ exmtrans('2factor.message.recovery_login_description') }}
                </p>

                @if($errors->has('recovery_code')) @foreach($errors->get('recovery_code') as $message)
                <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i>{{$message}}</label></br>
                @endforeach @endif

                <input type="text" class="form-control" placeholder="{{ exmtrans('2factor.message.input_recovery_code') }}" name="recovery_code" autocomplete="off" required />
            </div>

            <div class="row">
                <div class="col-xs-12 col-sm-8 col-sm-offset-2">
                    <input type="hidden" name="_token" value="{{ csrf_token() }}">
                    <button type="submit" class="btn btn-primary btn-block btn-flat submit_disabled">{{ exmtrans('2factor.login') }}</button>
                </div>
            </div>
        </form>
        @else
        <div class="form-group mb-3">
            <p>{{ exmtrans('2factor.message.recovery_no_codes') }}</p>
        </div>
        @endif

        <div style="margin:10px 0; text-align:center;">
            <p><a href="{{admin_url('auth-2factor')}}">{{ exmtrans('2factor.message.back_to_verify') }}</a></p>
            <p><a href="{{admin_url('auth-2factor/logout')}}">{{ trans('admin.back') }}</a></p>
        </div>

<!-- /.login-box -->
@endsection
