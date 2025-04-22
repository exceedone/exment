@extends('exment::auth.layout')
@section('content')
    <p class="login-box-msg">{{ trans('admin.login') }}</p>

    @if($show_default_form)
        <form action="{{ admin_url('auth/login') }}" method="post">
            <div class="mb-3 {!! !$errors->has('username') ?: 'is-invalid' !!}">
                @if($errors->has('username')) 
                    @foreach($errors->get('username') as $message)
                        <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i>{{$message}}</label><br>
                    @endforeach 
                @endif
                <div class="position-relative">
                    <input type="text" class="form-control" placeholder="{{ exmtrans('login.email_or_usercode') }}" name="username" value="{{ old('username') }}" required>
                    <span class="position-absolute top-50 end-0 translate-middle-y pe-3"><i class="fa fa-user"></i></span>
                </div>
            </div>
            <div class="mb-3 {!! !$errors->has('password') ?: 'is-invalid' !!}">
                @if($errors->has('password')) 
                    @foreach($errors->get('password') as $message)
                        <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i>{{$message}}</label><br>
                    @endforeach 
                @endif
                <div class="position-relative">
                    <input type="password" class="form-control" placeholder="{{ trans('admin.password') }}" name="password" required>
                    <span class="position-absolute top-50 end-0 translate-middle-y pe-3"><i class="fa fa-lock"></i></span>
                </div>
            </div>
            <div class="row">
                <div class="col-12 text-center mb-2">
                    @if(config('admin.auth.remember'))
                        <label class="form-check">
                            <input class="form-check-input" type="checkbox" name="remember" value="1" {{ old('remember') ? 'checked' : '' }}>
                            {{ trans('admin.remember_me') }}
                        </label>
                    @endif
                </div>
                <div class="col-12 col-sm-8 offset-sm-2 text-center">
                    <input type="hidden" name="_token" value="{{ csrf_token() }}">
                    @include('exment::auth.login_providers')
                </div>
            </div>
        </form>

        @if($show_default_login_provider)
            <div style="margin-top:10px; text-align:center;">
                <p class="m-0"><a href="{{admin_url('auth/forget')}}" class="text-decoration-none">{{ exmtrans('login.forget_password') }}</a></p>
            </div>
        @endif
    @endif

    @if(count($login_providers) > 0)
        <div class="social-auth-links text-center m-0">
            @if($show_default_form)
                <p class="m-1">- OR -</p>
            @endif
            @foreach($login_providers as $login_provider_name => $login_provider)
                @include('exment::auth.login_button_style')
            @endforeach
            @foreach($login_providers as $login_provider_name => $login_provider)
                <a href="{{ $login_provider['login_url'] }}" class="btn w-100 mt-1 btn-social click_disabled {{ $login_provider['btn_name'] ?? '' }}">
                    <i class=" fa {{ $login_provider['font_owesome'] ?? '' }}"></i> {{ $login_provider['display_name'] }}
                </a>
            @endforeach
            @if($errors->has('sso_error'))
                <div class="is-invalid">
                    @foreach($errors->get('sso_error') as $message)
                        <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i>{{$message}}</label><br>
                    @endforeach 
                </div>
            @endif
        </div>
    @endif
@endsection