@extends('exment::install.layout') 
@section('content')
    <p class="login-box-msg text-center">{{ trans('admin.setting') }}(1/4) : Language</p>

    <form action="{{ admin_url('install') }}" method="post">
        <div class="mb-3 {!! !$errors->has('common_error') ?: 'has-error' !!}">
            @if($errors->has('common_error')) 
                @foreach($errors->get('common_error') as $message)
                    <label class="form-label text-danger" for="inputError">
                        <i class="fas fa-times-circle"></i> {{$message}}
                    </label><br>
                @endforeach 
            @endif
        </div>

        <div class="mb-3 {!! !$errors->has('locale') ?: 'has-error' !!}">
            @if($errors->has('locale')) 
                @foreach($errors->get('locale') as $message)
                    <label class="form-label text-danger" for="inputError">
                        <i class="fas fa-times-circle"></i> {{$message}}
                    </label><br>
                @endforeach 
            @endif

            <label class="form-label fw-bold">Language:</label>
            <select name="locale" class="form-select">
                @foreach($locale_options as $key => $value)
                    <option value="{{$key}}" {{ $key == $locale_default ? 'selected' : '' }}>{{$value}}</option>
                @endforeach
            </select>
        </div>

        <div class="mb-3 {!! !$errors->has('timezone') ?: 'has-error' !!}">
            @if($errors->has('timezone')) 
                @foreach($errors->get('timezone') as $message)
                    <label class="form-label text-danger" for="inputError">
                        <i class="fas fa-times-circle"></i> {{$message}}
                    </label><br>
                @endforeach 
            @endif

            <label class="form-label fw-bold">Timezone:</label>
            <select name="timezone" class="form-select">
                @foreach($timezone_options as $key => $value)
                    <option value="{{$key}}" {{ $key == $timezone_default ? 'selected' : '' }}>{{$value}}</option>
                @endforeach
            </select>
        </div>

        <div class="row">
            <div class="col-12 col-sm-8 offset-sm-2 d-flex justify-content-start">
                <input type="hidden" name="_token" value="{{ csrf_token() }}">
                <button type="submit" class="btn btn-primary w-100 btn-install-next">{{ trans('admin.next') }}</button>
            </div>
        </div>
    </form>
@endsection