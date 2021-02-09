<div class="{{$viewClass['form-group']}} {!! ($errors->has($errorKey['start'].'start') || $errors->has($errorKey['end'].'end')) ? 'has-error' : ''  !!}">

    <label for="{{$id['start']}}" class="{{$viewClass['label']}} control-label">{{$label}}</label>

    <div class="{{$viewClass['field']}}">

        @include('admin::form.error')

        <div class="row" style="width: 370px">
            <div class="col-lg-5">
                <div class="input-group">
                    <input type="text" name="{{$name['start']}}" value="{{ $old['start'] }}" class="form-control {{$class['start']}}" {!! $attributes !!} />
                </div>
            </div>
            
            <div class="col-lg-2" style="text-align:center; line-height:34px;">
            ～
            </div>

            <div class="col-lg-5">
                <div class="input-group">
                    <input type="text" name="{{$name['end']}}" value="{{ $old['end'] }}" class="form-control {{$class['end']}}" {!! $attributes !!} />

                </div>
            </div>
        </div>

        @include('admin::form.help-block')

    </div>
</div>
