@extends('exment::auth.layout') 
@section('content')
            <p class="login-box-msg">{{ exmtrans('login.password_reset') }}</p>
    
            <form action="{{ route('password.email') }}" method="post">
                <div class="form-group has-feedback {!! !$errors->has('email') ?: 'has-error' !!}">
    
                    @if($errors->has('email')) @foreach($errors->get('email') as $message)
                    <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i>{{$message}}</label></br>
                    @endforeach @endif
    
                    <input type="input" class="form-control" placeholder="{{ exmtrans('user.email') }}" name="email" value="{{ old('email') }}" required>
                    <span class="glyphicon glyphicon-envelope form-control-feedback"></span>
                </div>  
                <div class="row">
                    <!-- /.col -->
                    <div class="col-xs-8 col-md-offset-2">
                        <input type="hidden" name="_token" value="{{ csrf_token() }}">
                        <button type="submit" class="btn btn-primary btn-block btn-flat submit_disabled">{{ exmtrans('login.password_reset') }}</button>
                    </div>
                    <!-- /.col -->
                </div>
            </form>
            <div style="margin:10px 0; text-align:center;">
                <p><a href="{{admin_url('auth/login')}}">{{ exmtrans('login.back_login_page') }}</a></p>
            </div>
@endsection
