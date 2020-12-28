@extends('exment::install.layout') 
@section('content')
        <p class="login-box-msg">{{ trans('admin.setting') }}(2/4) : {{exmtrans('install.database.header')}}</p>

        <form action="{{ admin_url('install') }}" method="post">
            <div class="form-group has-feedback {!! !$errors->has('database_canconnection') ?: 'has-error' !!}">

                @if($errors->has('database_canconnection')) @foreach($errors->get('database_canconnection') as $message)
                <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i>{{$message}}</label></br>
                @endforeach @endif
            </div>

            <div class="form-group has-feedback {!! !$errors->has('connection') ?: 'has-error' !!}">

                @if($errors->has('connection')) @foreach($errors->get('connection') as $message)
                <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i>{{$message}}</label></br>
                @endforeach @endif

                <label>{{exmtrans('install.database.connection')}}:</label>
                <select name="connection" class="form-control">
                    @foreach($connection_options as $key => $value)
                        <option value="{{$key}}" {{ $key == old('connection', $connection_default) ? 'selected' : '' }}>{{$value}}</option>
                    @endforeach
                </select>
            </div>

            <div class="form-group has-feedback {!! !$errors->has('host') ?: 'has-error' !!}">

                @if($errors->has('host')) @foreach($errors->get('host') as $message)
                <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i>{{$message}}</label></br>
                @endforeach @endif

                <label>{{exmtrans('install.database.host')}}:</label>
                <input type="text" class="form-control" name="host" value="{{ old('host', $host) }}" required />
            </div>

            <div class="form-group has-feedback {!! !$errors->has('port') ?: 'has-error' !!}">

                @if($errors->has('port')) @foreach($errors->get('port') as $message)
                <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i>{{$message}}</label></br>
                @endforeach @endif

                <label>{{exmtrans('install.database.port')}}:</label>
                <input type="text" class="form-control" name="port" value="{{ old('port', $port) }}" required />
            </div>

            <div class="form-group has-feedback {!! !$errors->has('database') ?: 'has-error' !!}">
                @if($errors->has('database')) @foreach($errors->get('database') as $message)
                <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i>{{$message}}</label></br>
                @endforeach @endif

                <label>{{exmtrans('install.database.database')}}:</label>
                <input type="text" class="form-control" name="database" value="{{ old('database', $database) }}" required />
            </div>

            <div class="form-group has-feedback {!! !$errors->has('username') ?: 'has-error' !!}">
                @if($errors->has('username')) @foreach($errors->get('username') as $message)
                <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i>{{$message}}</label></br>
                @endforeach @endif

                <label>{{exmtrans('install.database.username')}}:</label>
                <input type="text" class="form-control" name="username" value="{{ old('username', $username) }}" required />
            </div>

            <div class="form-group has-feedback {!! !$errors->has('password') ?: 'has-error' !!}">
                @if($errors->has('password')) @foreach($errors->get('password') as $message)
                <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i>{{$message}}</label></br>
                @endforeach @endif

                <label>{{exmtrans('install.database.password')}}:</label>
                <input type="password" class="form-control" name="password" value="{{ old('password', $password) }}" />
            </div>

            <div class="row">
                <!-- /.col -->
                <div class="col-xs-12 col-sm-4">
                    <a href="{{admin_urls('install', 'reset')}}" class="btn btn-default btn-block btn-flat click_disabled">{{trans('admin.reset')}}</a>
                </div>
                
                <div class="col-xs-12 col-sm-8">
                    <input type="hidden" name="_token" value="{{ csrf_token() }}">
                    <button type="submit" class="btn btn-primary btn-block btn-flat">{{ trans('admin.next') }}</button>
                </div>
                <!-- /.col -->
            </div>
        </form>
        
@endsection