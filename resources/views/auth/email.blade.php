@extends('exment::auth.layout') 
@section('content')
            <p class="login-box-msg">{{ exmtrans('login.password_reset') }}</p>
    
            <form action="{{ route('password.email') }}" method="post">
            <div class="mb-3 position-relative {!! !$errors->has('email') ?: 'has-error' !!}">
                @if($errors->has('email')) @foreach($errors->get('email') as $message)
                <label class="control-label" for="inputError"><i class="fas fa-times-circle"></i>{{$message}}</label></br>
                @endforeach @endif
                
                <input type="text" class="form-control pe-5 {{ $errors->has('email') ? 'is-invalid' : '' }}" placeholder="{{ exmtrans('user.email') }}" name="email" value="{{ old('email') }}" required>
                <span class="fas fa-envelope position-absolute end-0 top-50 translate-middle-y pe-3"></span>
            </div>
                <div class="row">
                    <!-- /.col -->
                    <div class="col-8 offset-md-2 text-center">
                        <input type="hidden" name="_token" value="{{ csrf_token() }}">
                        <button type="submit" class="btn w-100 btn-primary btn-block btn-flat submit_disabled">{{ exmtrans('login.password_reset') }}</button>
                    </div>
                    <!-- /.col -->
                </div>
            </form>
            <div style="margin:10px 0; text-align:center;">
                <p><a href="{{admin_url('auth/login')}}" class="text-decoration-none">{{ exmtrans('login.back_login_page') }}</a></p>
            </div>
@endsection
