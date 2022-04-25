@foreach($form_providers as $login_provider_name => $login_provider)
@include('exment::auth.login_button_style')
@endforeach

@if(isset($show_default_login_provider) && $show_default_login_provider)
<button type="submit" class="btn btn-primary btn-block btn-flat submit_disabled">{{ trans('admin.login') }}</button>
@endif

@foreach($form_providers as $login_provider_name => $login_provider)
<button type="submit" class="btn btn-primary btn-block btn-flat submit_disabled {{ $login_provider['btn_name'] ?? '' }}" name="login_setting_{{$login_provider_name}}" value="1">
    {{ $login_provider['display_name'] }}
</button>
@endforeach
