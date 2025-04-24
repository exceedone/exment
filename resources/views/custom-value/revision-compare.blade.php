<div class="box box-revision-compare custom-border-secondary card p-2">
    <div class="box-header with-border">
        <h3 class="box-title float-start p-1 px-3">
            {{exmtrans('revision.revision')}}
        </h3>
        @if($has_edit_permission)
        <div class="btn-group float-end" style="margin-right: 5px">
            <a href="{{$custom_value->getUrl(['uri' => 'edit'])}}" class="btn btn-sm btn-primary p-2" title="{{ trans('admin.edit') }}">
                <i class="fa fa-edit"></i><span class="d-none d-md-inline"> {{ trans('admin.edit') }}</span>
            </a>
        </div>
        @endif
        <div class="btn-group float-end" style="margin-right: 5px">
            <a href="{{$show_url}}" class="btn btn-sm btn-default p-2" title="{{ trans('admin.show') }}">
                <i class="fa fa-eye"></i><span class="d-none d-md-inline"> {{ trans('admin.show') }}</span>
            </a>
        </div>
        <div class="btn-group float-end" style="margin-right: 5px">
            <a href="{{$custom_value->getUrl(['list' => true])}}" class="btn btn-sm btn-default p-2" title="{{ trans('admin.list') }}">
                <i class="fa fa-list"></i><span class="d-none d-md-inline"> {{ trans('admin.list') }}</span>
            </a>
        </div>
        {!! $change_page_menu !!}
    </div><!-- /.box-header -->
    <div class="box-body" style="display: block;">
        <div class="form-horizontal">
            <div class="box-body">
                <div class="fields-group">
                    <div class="form-group row pt-4" style="margin-bottom:2em;">
                        <label class="col-sm-2 col-form-label">{{exmtrans('revision.revision_select')}}</label>
                        <div class="col-sm-5">
                            <select id="revisions" data-add-select2 data-add-select2-allow-clear="false">
                                @foreach($revisions as $index => $revision)
                                <option value="{{$revision->suuid}}" {{ $revision->suuid == $revision_suuid ? 'selected' : '' }}>
                                    No.{{$revision->revision_no}}
                                    &nbsp;
                                    {{$revision->updated_at}}
                                    &nbsp;({{ exmtrans("common.updated_user") }}&nbsp;:&nbsp;{{ $revision->user }})
                                    @if($revision->suuid == $newest_revision_suuid)
                                    &nbsp;{{exmtrans('revision.new')}}
                                    @endif
                                </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-sm-12">
                            <h4 class="field-header box-header with-border pb-2" style="margin: 0px 70px;">{{exmtrans('revision.compare_revision')}}</h4>
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
    .old-col .box-diff .box-body{
        background-color: #ffe9e9;
    }
    .new-col .box-diff .box-body{
        background-color: #e9ffe9;
    }
</style>