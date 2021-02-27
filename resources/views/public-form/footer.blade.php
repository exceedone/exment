<div class="box-footer" style="background-color: inherit;">

    {{ csrf_field() }}

    <div class="col-md-{{$width['label']}}">
    </div>

    <div class="col-md-{{$width['field']}}">
        <div class="text-center">
            @if($useRecaptchaV2 || $useRecaptchaV3)
                @if($errors->has('g-recaptcha-response'))
                    <div class="has-error">
                    @foreach($errors->get('g-recaptcha-response') as $message)
                        <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i> {{$message}}</label><br/>
                    @endforeach
                    </div>
                @endif
            @endif

            @if($useRecaptchaV2)
            {!! no_captcha()->display() !!}
            @elseif($useRecaptchaV3)
            {{ no_captcha()->input('g-recaptcha-response') }}
            @endif

            @if(in_array('submit', $buttons))
            <div class="">
                <button id="admin-submit" type="submit" class="submit_disabled btn btn-primary">{{ $submitLabel ?? trans('admin.submit') }}</button>
            </div>
            @endif
        </div>
    </div>
</div>

@if($useRecaptchaV2)
{!! no_captcha()->script() !!}
@elseif($useRecaptchaV3)
{!! no_captcha()->script() !!}
{!! no_captcha()->getApiScript() !!}
<script>
    grecaptcha.ready(function() {
        window.noCaptcha.render('login', function (token) {
            document.querySelector('#g-recaptcha-response').value = token;
        });
    });
</script>
@endif