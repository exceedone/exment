@extends('exment::auth.layout') 
@section('content')
        <p class="login-box-msg">{{ trans('admin.login') }}</p>

        @if(count($login_providers) == 0)
        <form action="{{ admin_base_path('auth/login') }}" method="post">
            <div class="form-group has-feedback {!! !$errors->has('username') ?: 'has-error' !!}">

                @if($errors->has('username')) @foreach($errors->get('username') as $message)
                <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i>{{$message}}</label></br>
                @endforeach @endif

                <input type="input" class="form-control" placeholder="{{ exmtrans('login.email_or_usercode') }}" name="username" value="{{ old('username') }}">
                <span class="glyphicon glyphicon-envelope form-control-feedback"></span>
            </div>
            <div class="form-group has-feedback {!! !$errors->has('password') ?: 'has-error' !!}">

                @if($errors->has('password')) @foreach($errors->get('password') as $message)
                <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i>{{$message}}</label></br>
                @endforeach @endif

                <input type="password" class="form-control" placeholder="{{ trans('admin.password') }}" name="password">
                <span class="glyphicon glyphicon-lock form-control-feedback"></span>
            </div>
            <div class="row">
                <!-- /.col -->
                <div class="col-xs-4 col-md-offset-4">
                    <input type="hidden" name="_token" value="{{ csrf_token() }}">
                    <button type="submit" class="btn btn-primary btn-block btn-flat">{{ trans('admin.login') }}</button>
                </div>
                <!-- /.col -->
            </div>
        </form>
        
        <div style="margin:10px 0; text-align:center;">
            <p><a href="{{admin_base_path('auth/forget')}}">{{ exmtrans('login.forget_password') }}</a></p>
        </div>
        @else
        @foreach($login_providers as $login_provider_name => $login_provider)
        <style>
        .{{ $login_provider['btn_name'] }}{
          {{ isset($login_provider['background_color']) ? 'background-color:'.$login_provider['background_color'].';' : ''}}
          {{ isset($login_provider['font_color']) ? 'color:'.$login_provider['font_color'].';' : '' }}
        }
        .{{ $login_provider['btn_name'] }}:hover, .{{ $login_provider['btn_name'] }}:focus,.{{ $login_provider['btn_name'] }}:active{
          {{ isset($login_provider['background_color_hover']) ? 'background-color:'.$login_provider['background_color_hover'].';' : ''}}              
          @if(isset($login_provider['font_color']) || !isset($login_provider['font_color_hover']))
          color: {{ isset($login_provider['font_color_hover']) ? $login_provider['font_color_hover'] : $login_provider['font_color'] }};              
          @endif
        }
        </style>

        <a href="{{ admin_base_path('auth/login/'.$login_provider_name) }}" class="btn btn-block btn-social btn-flat {{ $login_provider['btn_name'] ?? '' }}">
            <i class="fa {{ $login_provider['font_owesome'] ?? '' }}"></i> Sign in using {{ $login_provider['display_name'] }}</a>
        @endforeach
        @endif

<!-- /.login-box -->
@endsection