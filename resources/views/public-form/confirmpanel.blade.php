
<form method="post" accept-charset="UTF-8" class="form-horizontal click_disabled_submit block_custom_value_show">
    <div class="fields-group">
        @if(isset($confirm_title))
        <div class="form-group">
            <h2 class="col-md-8 col-md-offset-2 text-center">
                {{ $confirm_title }}
            </h2>
        </div>
        @endif

        @if(isset($confirm_text))
        <div class="form-group">
            <div class="col-md-8 col-md-offset-2">
                {!! html_clean($confirm_text) !!}
            </div>
        </div>
        @endif

        @if(isset($gridShows) && boolval($gridShows))
        @include('exment::form.gridshow-item')
        @elseif(isset($fields))
            @foreach($fields as $field)
                {!! $field->render() !!}
            @endforeach        
        @endif


        @foreach($relations as $relation)
            {!! $relation->render() !!}
        @endforeach

        <div class="box-footer" style="background-color: inherit;">

            {{ csrf_field() }}

            <div class="col-md-2">
            </div>

            <div class="col-md-8">
                <div class="text-center">
                    <div class="">
                        <button style="margin-right: 2em;" id="admin-back" type="submit" name="admin-back" class="submit_disabled btn btn-default" formaction="{{$back_action}}">{{ trans('admin.back') }}</button>
                        <button id="admin-submit" type="submit" class="submit_disabled btn btn-primary" formaction="{{$action}}" >{{ $submitLabel ?? trans('admin.submit') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>