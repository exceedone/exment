<div class="box box-revision-compare">
    <div class="box-header with-border">
        <h3 class="box-title">
            リビジョン比較
        </h3>
        <div class="btn-group pull-right" style="margin-right: 5px">
            <a href="{{$custom_value->getUrl(false, ['uri' => 'edit'])}}" class="btn btn-sm btn-primary" title="編集">
                <i class="fa fa-edit"></i><span class="hidden-xs"> 編集</span>
            </a>
        </div>
        <div class="btn-group pull-right" style="margin-right: 5px">
            <a href="{{$custom_value->getUrl()}}" class="btn btn-sm btn-default" title="表示">
                <i class="fa fa-eye"></i><span class="hidden-xs"> 表示</span>
            </a>
        </div>
        <div class="btn-group pull-right" style="margin-right: 5px">
            <a href="{{$custom_value->getUrl(false, ['list' => true])}}" class="btn btn-sm btn-default" title="一覧">
                <i class="fa fa-list"></i><span class="hidden-xs"> 一覧</span>
            </a>
        </div>
        {!! $change_page_menu !!}
    </div><!-- /.box-header -->
    <div class="box-body" style="display: block;">
        <div class="form-horizontal">
            <div class="box-body">
                <div class="fields-group">
                
                    <div class="form-group " style="margin-bottom:3em;">
                        <label class="col-sm-2 control-label">リビジョン選択</label>
                        <div class="col-sm-5">
                            <select id="revisions" data-add-select2>
                                @foreach($revisions as $index => $revision)
                                <option value="{{$revision->suuid}}" {{ $revision->suuid == $revision_suuid ? 'selected' : '' }}>
                                    No.{{$revision->revision_no}}
                                    &nbsp;
                                    {{$revision->updated_at}}
                                    &nbsp;({{ exmtrans("common.created_user") }}：{{ $revision->user }})
                                    @if($revision->suuid == $newest_revision_suuid)
                                    &nbsp;最新
                                    @endif
                                </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    
                    <div id="pjax-container-revision">
                        @include('exment::custom-value.revision-compare-inner') 
                    </div>
                </div><!-- /.fields-group -->
            </div>
            <!-- /.box-body -->
        </div>
    </div><!-- /.box-body -->
</div>


<style>
    .box-revision-compare .form-horizontal .form-group{
        display: flex;
        flex-wrap: wrap;
    }
    
    .box-revision-compare .box.box-solid.box-default.box-show, .box-revision-compare .box.box-solid.box-default.box-show .box-body{
        height: 100%;
    }

    .old-col .box-diff .box-body{
        background-color: #ffe9e9;
    }
    .new-col .box-diff .box-body{
        background-color: #e9ffe9;
    }
</style>