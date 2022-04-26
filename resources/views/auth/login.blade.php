@extends('exment::auth.layout') 
@section('content')
        <p class="login-box-msg">{{ trans('admin.login') }}</p>

        @if($show_default_form)
            <form action="{{ admin_url('auth/login') }}" method="post">
                <div class="form-group has-feedback {!! !$errors->has('username') ?: 'has-error' !!}">

                    @if($errors->has('username')) @foreach($errors->get('username') as $message)
                    <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i>{{$message}}</label></br>
                    @endforeach @endif

                    <div style="position:relative;">
                        <input type="input" class="form-control" placeholder="{{ exmtrans('login.email_or_usercode') }}" name="username" value="{{ old('username') }}" required>
                        <span class="glyphicon glyphicon-user form-control-feedback"></span>
                    </div>
                </div>
                <div class="form-group has-feedback {!! !$errors->has('password') ?: 'has-error' !!}">

                    @if($errors->has('password')) @foreach($errors->get('password') as $message)
                    <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i>{{$message}}</label></br>
                    @endforeach @endif

                    <div style="position:relative;">
                        <input type="password" class="form-control" placeholder="{{ trans('admin.password') }}" name="password" required>
                        <span class="glyphicon glyphicon-lock form-control-feedback"></span>
                    </div>
                </div>
                <div class="row">
                    <div class="col-xs-12 text-center">
                        @if(config('admin.auth.remember'))
                        <div class="checkbox icheck">
                            <label>
                                <input type="checkbox" name="remember" value="1" {{ old('remember') ? 'checked' : '' }}>
                                {{ trans('admin.remember_me') }}
                            </label>
                        </div>
                        @endif
                    </div>
                    <!-- /.col -->
                    <div class="col-xs-12 col-sm-8 col-sm-offset-2">
                        <input type="hidden" name="_token" value="{{ csrf_token() }}">
                        
                        @include('exment::auth.login_providers')
                    </div>
                    <!-- /.col -->
                </div>
            </form>
            
            @if($show_default_login_provider)
            <div style="margin:10px 0; text-align:center;">
                <p><a href="{{admin_url('auth/forget')}}">{{ exmtrans('login.forget_password') }}</a></p>
            </div>
            @endif
        @endif

        @if(count($login_providers) > 0)
        <div class="social-auth-links text-center">
        @if($show_default_form)
        <p>- OR -</p>
        @endif

        @foreach($login_providers as $login_provider_name => $login_provider)
        @include('exment::auth.login_button_style')
        @endforeach

        @foreach($login_providers as $login_provider_name => $login_provider)
        <a href="{{ $login_provider['login_url'] }}" class="btn btn-block btn-social btn-flat click_disabled {{ $login_provider['btn_name'] ?? '' }}">
            <i class="fa {{ $login_provider['font_owesome'] ?? '' }}"></i> {{ $login_provider['display_name'] }}
        </a>
        @endforeach

        @if($errors->has('sso_error'))
        <div class="has-error">
        @foreach($errors->get('sso_error') as $message)
        <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i>{{$message}}</label></br>
        @endforeach 
        </div>
        @endif

        </div>
        @endif

<!-- /.login-box -->
@endsection