<div class="{{$viewClass['form-group']}}">

<label for="{{array_get($id, 'status_from')}}" class="{{array_get($viewClass, 'label')}} control-label">{{$label}}</label>

    <div class="{{array_get($viewClass, 'field')}}">

        @if(isset($action_id))
        <div class="workflow_table_item">
            <label class="control-label small">ID</label>
            <span style="padding-left: 1em; padding-top:7px; margin-bottom:0;">
                {{$action_id}}
            </span>
        </div>
        @endif
        
        <div class="workflow_table_item {!! !$errors->has($errorKey['action_name']) ?: 'has-error' !!}">
            @include('exment::form.field.error', ['errorKeyFix' => $errorKey['action_name']])

            <label for="{{array_get($id, 'action_name')}}" class="control-label small asterisk">{{exmtrans('workflow.action_name')}}</label>
            <div class="input-group">
                <span class="input-group-addon"><i class="fa fa-pencil fa-fw"></i></span>
                <input id="{{array_get($id, 'action_name')}}" type="text" name="{{array_get($name, 'action_name')}}" value="{{ old(array_get($column, 'action_name'), array_get($value, 'action_name'))}}" class="form-control {{array_get($class, 'action_name')}}" style="max-width:999999px" placeholder="{{exmtrans('workflow.action_name')}}" required>
            </div>
        </div>
        
        <div class="workflow_table_item {!! !$errors->has($errorKey['status_from']) ?: 'has-error' !!}">
            @include('exment::form.field.error', ['errorKeyFix' => $errorKey['status_from']])

            <label for="{{array_get($id, 'status_from')}}" class="control-label small asterisk">
                {{exmtrans('workflow.status_from')}}
                <i class="fa fa-info-circle" data-help-text="{{exmtrans('workflow.help.status_from')}}" data-help-title="{{exmtrans('workflow.status_from')}}"></i>
            </label>

            <select class="form-control {{array_get($class, 'status_from')}}" style="width: 100%;" name="{{array_get($name, 'status_from')}}" {!! $attributes !!} required >
                <option value=""></option>
                @foreach($options as $select => $option)
                    <option value="{{$select}}" {{ $select == old(array_get($column, 'status_from'), array_get($value, 'status_from')) ? 'selected':'' }}>{{$option}}</option>
                @endforeach
            </select>
        </div>

        @include('admin::form.help-block')

    </div>
</div>
