<div class="box custom-border-info card p-2">
    <!-- box-header -->
    <div class="box-header with-border pb-2">
        <h3 class="box-title float-start">{{ exmtrans('plugin.upload_header') }}</h3>

        <div class="box-tools float-end">
            <button type="button" class="btn btn-box-tool" data-widget="collapse">
                <i class="fa fa-minus"></i>
            </button>
            <button type="button" class="btn btn-box-tool" data-widget="remove"><i class="fa fa-times"></i></button>
        </div>
    </div>
    <!-- /.box-header -->
    <!-- form start -->
    <form class="" method="post" action="{{ admin_url('plugin') }}" enctype="multipart/form-data">
        <!-- box-body -->
        <div class="box-body">
            <div class="fields-group">
                <div class="form-group ps-2 pt-3 pb-5">
                    <input class="pb-4" required type="file" name="fileUpload" accept='.zip,application/octet-stream,application/zip,application/x-zip,application/x-zip-compressed'/><br/>
                    <span class="help-block">
                        <i class="fa fa-info-circle"></i>&nbsp;{{ exmtrans('plugin.extension') }}
                    </span>
                </div>
            </div>
            @if(session()->has('errorMess'))
                <span class="font-weight-bold" style="color: red"><i class="fa fa-times-circle-o"></i> {!! session('errorMess') !!}</span>
            @endif
             
        </div>
        <!-- /.box-body -->

        <div class="box-footer ps-2">
            <input type="hidden" name="_token" value="{{ csrf_token() }}">
            <button type="submit" class="btn btn-info btn-upload submit_disabled text-white" data-loading-text="<i class='fa fa-spinner fa-spin '></i> {{trans('admin.upload')}}">{{trans('admin.upload')}}</button>
        </div>

    </form>
</div>

<script>
$(document).ready(function(){
    if($('input[type=file]').val()==''){
        $('.btn-upload').attr('disabled',true)
    }; 

    $('input[type=file]').change(function(){
        if($('input[type=file]').val()==''){
            $('.btn-upload').attr('disabled',true)
        } 
        else{
        $('.btn-upload').attr('disabled',false);
        }
    })
 });
</script>