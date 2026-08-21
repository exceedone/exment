<div class="box-footer" style="background-color: inherit;">

    {{ csrf_field() }}

    <div style="width: 100%; clear: both;">
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

            {!! $recaptchaWidget !!}

            @if(in_array('submit', $buttons))
            <div class="">
                <button id="admin-submit" type="submit" class="submit_disabled btn btn-primary">{{ $submitLabel ?? trans('admin.submit') }}</button>
            </div>
            @endif
        </div>
    </div>
</div>

{!! $recaptchaScript !!}