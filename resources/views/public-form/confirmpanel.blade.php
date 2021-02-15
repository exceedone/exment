
<form method="post" accept-charset="UTF-8" class="form-horizontal click_disabled_submit">
    <div class="fields-group">
        @foreach($fields as $field)
            {!! $field->render() !!}
        @endforeach


        <div class="box-footer" style="background-color: inherit;">

            {{ csrf_field() }}

            <div class="col-md-2">
            </div>

            <div class="col-md-8">
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

                    <div class="">
                        <button style="margin-right: 2em;" id="admin-back" type="submit" name="admin-back" class="submit_disabled btn btn-default" formaction="{{$back_action}}">{{ trans('admin.back') }}</button>
                        <button id="admin-submit" type="submit" class="submit_disabled btn btn-primary" formaction="{{$action}}" >{{ $submitLabel ?? trans('admin.submit') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>