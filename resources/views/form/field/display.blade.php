<div class="{{$viewClass['form-group']}}">
    <label class="{{$viewClass['label']}} control-label" style="padding-top:10px;">{{$label}}</label>
    <div class="{{$viewClass['field']}}">
        <div class="no-margin">
            <!-- /.box-header -->
            <div class="box-body" style="padding-bottom:0;">
                <span class="{{$class}}">
                {{ $value }}
                </span>&nbsp;
            </div><!-- /.box-body -->
        </div>

        @include('admin::form.help-block')

    </div>
</div>