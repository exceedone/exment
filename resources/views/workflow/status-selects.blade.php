<div class="{{$viewClass['form-group']}} {!! ($errors->has($errorKey['status_from'].'status_from')) ? 'has-error' : ''  !!}">

<label for="{{$id['status_from']}}" class="{{$viewClass['label']}} control-label">{{$label}}</label>

    <div class="{{$viewClass['field']}}">

        @include('admin::form.error')

        <label for="{{$id['action_name']}}" class="control-label small asterisk">{{exmtrans('workflow.action_name')}}</label>
        <div class="input-group">
            <span class="input-group-addon"><i class="fa fa-pencil fa-fw"></i></span>
            <input id="$id['action_name']}}" type="text" name="{{$name['action_name']}}" value="{{ old($column['action_name'], $value['action_name'])}}" class="form-control {{$class['action_name']}}" style="max-width:999999px">
        </div>
        
        <label for="{{$id['status_from']}}" class="control-label small asterisk">{{exmtrans('workflow.status_from')}}</label>
        <select class="form-control {{$class['status_from']}}" style="width: 100%;" name="{{$name['status_from']}}" {!! $attributes !!} >
            <option value=""></option>
            @foreach($options as $select => $option)
                <option value="{{$select}}" {{ $select == old($column['status_from'], $value['status_from']) ?'selected':'' }}>{{$option}}</option>
            @endforeach
        </select>

        @include('admin::form.help-block')

    </div>
</div>
