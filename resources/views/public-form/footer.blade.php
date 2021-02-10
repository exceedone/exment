<div class="box-footer" style="background-color: inherit;">

    {{ csrf_field() }}

    <div class="col-md-{{$width['label']}}">
    </div>

    <div class="col-md-{{$width['field']}}">
        <div class="text-center">
            @if($useRecaptcha)
            {!! NoCaptcha::renderJs() !!}
            {!! NoCaptcha::display() !!}
            @endif

            @if(in_array('submit', $buttons))
            <div class="">
                <button id="admin-submit" type="submit" class="btn btn-primary">{{ $submitLabel ?? trans('admin.submit') }}</button>
            </div>
            @endif
        </div>
    </div>
</div>