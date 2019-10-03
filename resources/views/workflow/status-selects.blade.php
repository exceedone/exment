<div class="{{$viewClass['form-group']}} {!! ($errors->has($errorKey['start'].'start') || $errors->has($errorKey['end'].'end')) ? 'has-error' : ''  !!}">

<label for="{{$id['start']}}" class="{{$viewClass['label']}} control-label">{{$label}}</label>

    <div class="{{$viewClass['field']}}">

        @include('admin::form.error')

        <input type="hidden" name="{{$name['start']}}"/>

        <select class="form-control {{$class['start']}}" style="width: 100%;" name="{{$name['start']}}" {!! $attributes !!} >
            <option value=""></option>
            @foreach($options as $select => $option)
                <option value="{{$select}}" {{ $select == old($column['start'], $value['start']) ?'selected':'' }}>{{$option}}</option>
            @endforeach
        </select>

        <input type="hidden" name="{{$name['end']}}"/>

        <select class="form-control {{$class['end']}}" style="width: 100%;" name="{{$name['end']}}" {!! $attributes !!} >
            <option value=""></option>
            @foreach($options as $select => $option)
                <option value="{{$select}}" {{ $select == old($column['end'], $value['end']) ?'selected':'' }}>{{$option}}</option>
            @endforeach
        </select>

        @include('admin::form.help-block')

    </div>
</div>
