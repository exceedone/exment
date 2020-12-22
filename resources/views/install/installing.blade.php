@extends('exment::install.layout') 
@section('content')
        <p class="login-box-msg">{{ trans('admin.setting') }}(4/4) : {{ exmtrans('install.installing.header')}}</p>

        <form action="{{ admin_url('install') }}" method="post">
            
            <div class="form-group has-feedback {!! !$errors->has('database_canconnection') ?: 'has-error' !!}">
                @if($errors->has('install_error')) @foreach($errors->get('install_error') as $message)
                <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i>{{$message}}</label></br>
                @endforeach @endif
            </div>

            <div class="row">
                <!-- /.col -->
                <p class="col-xs-12 col-sm-10 col-sm-offset-1">{{ exmtrans('install.help.installing') }}</p>

                <div class="col-xs-12 col-sm-8 col-sm-offset-2">
                    <input type="hidden" name="_token" value="{{ csrf_token() }}">
                    <button type="submit" class="btn btn-primary btn-block btn-flat btn-install-next">{{ exmtrans('install.installing.installing') }}</button>
                </div>
                <!-- /.col -->
            </div>
        </form>
        
@endsection