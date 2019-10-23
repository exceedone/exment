<div class="{{$viewClass['form-group']}}">

<label for="{{$id['status_from']}}" class="{{$viewClass['label']}} control-label">{{$label}}</label>

    <div class="{{$viewClass['field']}}">


        <div class="workflow_table_item {!! !$errors->has($errorKey['action_name']) ?: 'has-error' !!}">
            @include('exment::form.field.error', ['errorKeyFix' => $errorKey['action_name']])

            <label for="{{$id['action_name']}}" class="control-label small asterisk">{{exmtrans('workflow.action_name')}}</label>
            <div class="input-group">
                <span class="input-group-addon"><i class="fa fa-pencil fa-fw"></i></span>
                <input id="$id['action_name']}}" type="text" name="{{$name['action_name']}}" value="{{ old($column['action_name'], $value['action_name'])}}" class="form-control {{$class['action_name']}}" style="max-width:999999px" placeholder="{{exmtrans('workflow.action_name')}}" required>
            </div>
        </div>
        
        <div class="workflow_table_item {!! !$errors->has($errorKey['status_from']) ?: 'has-error' !!}">
            @include('exment::form.field.error', ['errorKeyFix' => $errorKey['status_from']])

            <label for="{{$id['status_from']}}" class="control-label small asterisk">
                {{exmtrans('workflow.status_from')}}
                <i class="fa fa-info-circle" data-help-text="{{exmtrans('workflow.help.status_from')}}" data-help-title="{{exmtrans('workflow.status_from')}}"></i>
            </label>

            <select class="form-control {{$class['status_from']}}" style="width: 100%;" name="{{$name['status_from']}}" {!! $attributes !!} required >
                <option value=""></option>
                @foreach($options as $select => $option)
                    <option value="{{$select}}" {{ $select == old($column['status_from'], $value['status_from']) ?'selected':'' }}>{{$option}}</option>
                @endforeach
            </select>
        </div>

        @include('admin::form.help-block')

    </div>
</div>
