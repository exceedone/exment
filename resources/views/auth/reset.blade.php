@extends('exment::auth.layout') 
@section('content')
            <p class="login-box-msg">{{ exmtrans('login.password_reset') }}</p>
    
            <form action="{{ route('password.request', ['token' => $token]) }}" method="post">
                    <div class="form-group has-feedback {!! !$errors->has('password') ?: 'has-error' !!}">
                        @if($errors->has('password')) @foreach($errors->get('password') as $message)
                        <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i>{{$message}}</label></br>
                        @endforeach @endif
        
                        <input type="password" class="form-control" placeholder="{{ trans('admin.password') }}" name="password" value="{{ old('password') }}" required>
                        <span class="glyphicon glyphicon-lock form-control-feedback"></span>
                    </div>
                    <div class="form-group has-feedback {!! !$errors->has('password_confirmation') ?: 'has-error' !!}">
                        @if($errors->has('password_confirmation')) @foreach($errors->get('password_confirmation') as $message)
                        <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i>{{$message}}</label></br>
                        @endforeach @endif
        
                        <input type="password" class="form-control" placeholder="{{ trans('admin.password_confirmation') }}" name="password_confirmation" value="{{ old('password') }}" required>
                        <span class="glyphicon glyphicon-lock form-control-feedback"></span>
                    </div>
                <div class="row">
                    <!-- /.col -->
                    <div class="col-xs-8 col-md-offset-2">
                        <input type="hidden" name="_token" value="{{ csrf_token() }}">
                        <input type="hidden" name="token" value="{{ $token }}">
                        <button type="submit" class="btn btn-primary btn-block btn-flat submit_disabled">{{ exmtrans('login.password_reset') }}</button>
                    </div>
                    <!-- /.col -->
                </div>
            </form>
            <div style="margin:10px 0; text-align:center;">
                <p><a href="{{admin_url('auth/login')}}">{{ exmtrans('login.back_login_page') }}</a></p>
            </div>
@endsection
