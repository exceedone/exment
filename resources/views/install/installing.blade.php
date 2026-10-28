@extends('exment::install.layout') 
@section('content')
        <p class="login-box-msg text-center">{{ trans('admin.setting') }}(4/4) : {{ exmtrans('install.installing.header')}}</p>

        <form action="{{ admin_url('install') }}" method="post">
            
            <div class="form-group text-danger {!! !$errors->has('database_canconnection') ?: 'has-error' !!}">
                @if($errors->has('install_error')) @foreach($errors->get('install_error') as $message)
                <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i>{{$message}}</label></br>
                @endforeach @endif
            </div>


            <div class="row">
                <!-- /.col -->
                <div class="col-xs-12 col-sm-10 col-sm-offset-1 ps-5" style="margin-bottom:2em;">
                    @if($errors->has('APP_DEBUG')) @foreach($errors->get('APP_DEBUG') as $message)
                    <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i>{{$message}}</label></br>
                    @endforeach @endif

                    <label class=" fw-bold">{{exmtrans('install.installing.debug')}}:</label>
                    <input type="checkbox" class="form-control" name="APP_DEBUG" value="1" {{ boolval(old('APP_DEBUG')) ? 'checked'  : '' }} />
                    <p class="small">
                        <i class="fa fa-info-circle"></i>&nbsp;{{exmtrans('install.help.debug')}}
                    </p>
                </div>

                <!-- /.col -->
                <p class="col-xs-12 col-sm-10 col-sm-offset-1 ps-5">{{ exmtrans('install.help.installing') }}</p>
            </div>
            
            <div class="row">
                <div class="col-xs-12 col-sm-4">
                    <a href="{{admin_urls('install', 'reset')}}" class="btn btn-default btn-block w-100 click_disabled">{{trans('admin.reset')}}</a>
                </div>
                
                <div class="col-xs-12 col-sm-8">
                    <input type="hidden" name="_token" value="{{ csrf_token() }}">
                    <button style="background-color: #3c8dbc;border-color: #367fa9;" type="submit" class="btn btn-primary btn-block w-100">{{ exmtrans('install.installing.installing') }}</button>
                </div>
                <!-- /.col -->
            </div>
        </form>
        
@endsection