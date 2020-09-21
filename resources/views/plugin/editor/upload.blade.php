<div class="box box-info">
    <!-- box-header -->
    <div class="box-header with-border">
        <h3 class="box-title">{{ exmtrans('plugincode.upload_header') }}</h3>

        <div class="box-tools pull-right">
            <button type="button" class="btn btn-box-tool" data-widget="collapse">
                <i class="fa fa-minus"></i>
            </button>
            <button type="button" class="btn btn-box-tool" data-widget="remove"><i class="fa fa-times"></i></button>
        </div>
    </div>
    <!-- /.box-header -->
    <!-- form start -->
    <form class="" method="post" action="{{ $url }}" enctype="multipart/form-data">
        <!-- box-body -->
        <div class="box-body">
            <div class="fields-group">
                <div class="form-group">
                    <input class="" required type="file" name="fileUpload[]" multiple /><br/>
                    <span class="help-block">
                        <i class="fa fa-info-circle"></i>&nbsp;{{ exmtrans('plugincode.message.upload_file', $filepath) }}
                    </span>
                    <span class="help-block">
                        <i class="fa fa-info-circle"></i>&nbsp;{{ exmtrans('plugincode.message.file_edit') }}
                    </span>
                    <span class="help-block">
                        <i class="fa fa-warning"></i>&nbsp;{{ exmtrans('plugincode.message.force_updated') }}
                    </span>
                </div>
            </div>
            @if(session()->has('errorMess'))
                <span class="font-weight-bold" style="color: red"><i class="fa fa-times-circle-o"></i> {!! session('errorMess') !!}</span>
            @endif
             
        </div>
        <!-- /.box-body -->

        <div class="box-footer">
            <input type="hidden" name="_token" value="{{ csrf_token() }}">
            <input type="hidden" id="nodeid" name="nodeid" value="{{ $nodeid }}">
            <button type="submit" class="btn btn-info btn-upload submit_disabled" data-loading-text="<i class='fa fa-spinner fa-spin '></i> {{trans('admin.upload')}}">{{trans('admin.upload')}}</button>
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