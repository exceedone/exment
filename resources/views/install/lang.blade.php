@extends('exment::install.layout') 
@section('content')
        <p class="login-box-msg">{{ trans('admin.setting') }}(1/4) : Language</p>

        <form action="{{ admin_url('install') }}" method="post">
            <div class="form-group has-feedback {!! !$errors->has('common_error') ?: 'has-error' !!}">
                @if($errors->has('common_error')) @foreach($errors->get('common_error') as $message)
                <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i>{{$message}}</label></br>
                @endforeach @endif
            </div>

            <div class="form-group has-feedback {!! !$errors->has('locale') ?: 'has-error' !!}">

                @if($errors->has('locale')) @foreach($errors->get('locale') as $message)
                <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i>{{$message}}</label></br>
                @endforeach @endif

                <label>Language:</label>
                <select name="locale" class="form-control">
                    @foreach($locale_options as $key => $value)
                        <option value="{{$key}}" {{ $key == $locale_default ? 'selected' : '' }}>{{$value}}</option>
                    @endforeach
                </select>
            </div>

            <div class="form-group has-feedback {!! !$errors->has('timezone') ?: 'has-error' !!}">

                @if($errors->has('timezone')) @foreach($errors->get('timezone') as $message)
                <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i>{{$message}}</label></br>
                @endforeach @endif

                <label>Timezone:</label>
                <select name="timezone" class="form-control">
                    @foreach($timezone_options as $key => $value)
                        <option value="{{$key}}" {{ $key == $timezone_default ? 'selected' : '' }}>{{$value}}</option>
                    @endforeach
                </select>
            </div>

            <div class="row">
                <!-- /.col -->
                <div class="col-xs-12 col-sm-8 col-sm-offset-2">
                    <input type="hidden" name="_token" value="{{ csrf_token() }}">
                    <button type="submit" class="btn btn-primary btn-block btn-flat btn-install-next">{{ trans('admin.next') }}</button>
                </div>
                <!-- /.col -->
            </div>
        </form>
        
@endsection