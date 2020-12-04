@foreach($items as $custom_table_id => $item)

<div class="worlfow_beginning  {!! !$errors->has('workflow_tables.' . $custom_table_id) ? '' : 'has-error' !!}">
    <div class="row">
        <div class="col-sm-12">
            <h4>{{ $item['custom_table']['table_view_name'] }}</h4>
            <input type="hidden" name="workflow_tables[{{$custom_table_id}}][custom_table][table_view_name]" value="{{ $item['custom_table']['table_view_name'] }}" />
        </div>
    </div>
    
    @if($errors->has('workflow_tables.' . $custom_table_id))
    <div class="row">
        <div class="col-sm-12">
        @foreach($errors->get('workflow_tables.' . $custom_table_id) as $message)
            <p><label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i> {{$message}}</label></p>
        @endforeach
        </div>
    </div>
    @endif
        

@if(isset($item['workflows']) && count($item['workflows']) > 0)
    <div class="row text-center">
        <div class="col-sm-4 col-sm-offset-1 bold">{{ exmtrans('workflow.workflow_view_name') }}</div>
        <div class="col-sm-2 bold">{{ exmtrans('common.available') }}</div>
        <div class="col-sm-2 bold">{{ exmtrans('workflow.active_start_date') }}</div>
        <div class="col-sm-2 bold">{{ exmtrans('workflow.active_end_date') }}</div>
    </div>
@endif

@foreach(array_get($item, 'workflows', []) as $workflow_id => $workflow)
    <div class="row form-group text-center">
        <div class="col-sm-4 col-sm-offset-1">
            <div class="form-control" style="border:none;">
                {{ array_get($workflow, 'workflow_view_name') }}
                <input type="hidden" name="workflow_tables[{{$custom_table_id}}][workflows][{{$workflow_id}}][workflow_view_name]" value="{{ array_get($workflow, 'workflow_view_name') }}" />
            </div>
        </div>

        <div class="col-sm-2">
            <input type="checkbox" data-add-icheck name="workflow_tables[{{$custom_table_id}}][workflows][{{$workflow_id}}][active_flg]" value="1" class="workflows_active_flg" {{ array_get($workflow, 'active_flg') == '1' ? 'checked' : '' }} />
        </div>

        @php
        $errorKey = "workflow_tables.{$custom_table_id}.workflows.{$workflow_id}.active_start_date";
        @endphp
        <div class="col-sm-2  {!! !$errors->has($errorKey) ? '' : 'has-error' !!}">
            @if($errors->has($errorKey))
                @foreach($errors->get($errorKey) as $message)
                    <label class="control-label" style="text-align:left;" for="inputError"><i class="fa fa-times-circle-o"></i> {{$message}}</label><br/>
                @endforeach
            @endif
            <input type="text" data-add-date name="workflow_tables[{{$custom_table_id}}][workflows][{{$workflow_id}}][active_start_date]" value="{{ array_get($workflow, 'active_start_date') }}" class="form-control w-100">
        </div>

        @php
        $errorKey = "workflow_tables.{$custom_table_id}.workflows.{$workflow_id}.active_end_date";
        @endphp
        <div class="col-sm-2  {!! !$errors->has($errorKey) ? '' : 'has-error' !!}">
            @if($errors->has($errorKey))
                @foreach($errors->get($errorKey) as $message)
                    <label class="control-label" style="text-align:left;" for="inputError"><i class="fa fa-times-circle-o"></i> {{$message}}</label><br/>
                @endforeach
            @endif
            <input type="text" data-add-date name="workflow_tables[{{$custom_table_id}}][workflows][{{$workflow_id}}][active_end_date]" value="{{ array_get($workflow, 'active_end_date') }}" class="form-control w-100">
        </div>

    </div>
@endforeach

<hr />
</div>
@endforeach