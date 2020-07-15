@extends('exment::auth.layout') 
@section('content')
            <p class="login-box-msg">{{ $caption }}</p>
    
            <form action="{{ admin_url('auth/change') }}" method="post">
                    <div class="form-group has-feedback {!! !$errors->has('current_password') ?: 'has-error' !!}">
                        @if($errors->has('current_password')) @foreach($errors->get('current_password') as $message)
                        <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i>{{$message}}</label></br>
                        @endforeach @endif
        
                        <div style="position:relative;">
                            <input type="password" class="form-control" placeholder="{{ exmtrans('user.current_password') }}" name="current_password" value="{{ old('password') }}" required>
                            <span class="glyphicon glyphicon-lock form-control-feedback"></span>
                        </div>
                    </div>
                    <div class="form-group has-feedback {!! !$errors->has('password') ?: 'has-error' !!}">
                        @if($errors->has('password')) @foreach($errors->get('password') as $message)
                        <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i>{{$message}}</label></br>
                        @endforeach @endif
        
                        <div style="position:relative;">
                            <input type="password" class="form-control" placeholder="{{ exmtrans('user.new_password') }}" name="password" value="{{ old('password') }}" required>
                            <span class="glyphicon glyphicon-lock form-control-feedback"></span>
                        </div>

                        <span class="help-block">
                            <i class="fa fa-info-circle"></i>&nbsp;{{\Exment::get_password_help()}}
                        </span>
                    </div>
                    <div class="form-group has-feedback {!! !$errors->has('password_confirmation') ?: 'has-error' !!}">
                        @if($errors->has('password_confirmation')) @foreach($errors->get('password_confirmation') as $message)
                        <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i>{{$message}}</label></br>
                        @endforeach @endif
        
                        <div style="position:relative;">
                            <input type="password" class="form-control" placeholder="{{ exmtrans('user.new_password_confirmation') }}" name="password_confirmation" value="{{ old('password') }}" required>
                            <span class="glyphicon glyphicon-lock form-control-feedback"></span>
                        </div>
                    </div>
                <div class="row">
                    <!-- /.col -->
                    <div class="col-xs-8 col-md-offset-2">
                        <input type="hidden" name="_token" value="{{ csrf_token() }}">
                        <button type="submit" class="btn btn-primary btn-block btn-flat submit_disabled">{{ exmtrans('user.password_change') }}</button>
                    </div>
                    <!-- /.col -->
                </div>
                <div style="margin:10px 0; text-align:center;">
                    <p><a href="{{admin_url('auth/logout')}}">{{ trans('admin.back') }}</a></p>
                </div>
            </form>
@endsection
