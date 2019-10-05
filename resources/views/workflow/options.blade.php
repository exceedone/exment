<div class="{{$viewClass['form-group']}} {!! ($errors->has($errorKey['commentType'].'commentType')) ? 'has-error' : ''  !!}">

<label for="{{$id['commentType']}}" class="{{$viewClass['label']}} control-label">{{$label}}</label>

    <div class="{{$viewClass['field']}}">

        @include('admin::form.error')

        <label for="{{$id['commentType']}}" class="control-label small asterisk">{{exmtrans('common.comment')}}</label>
        <select class="form-control {{$class['commentType']}}" style="width: 100%;" name="{{$name['commentType']}}" {!! $attributes !!} >
            <option value=""></option>
            @foreach($optionsCommentType as $select => $option)
                <option value="{{$select}}" {{ $select == (old($column['commentType'], $value['commentType']) ?? $defaultCommentType) ?'selected':'' }}>{{$option}}</option>
            @endforeach
        </select>



        <label for="{{$id['flowNextType']}}" class="control-label small asterisk">{{exmtrans('workflow.flow_next_type')}}</label>
        
        <div class="radio icheck">
            <label>
                <input type="radio" name="{{$name['flowNextType']}}" value="some" class="minimal {{$class['flowNextType']}}" {{ ($option == old($column['flowNextType'], $value['flowNextType'])) || ($value['flowNextType'] === 'some') || ($value['flowNextType'] === null) ?'checked':'' }} />&nbsp;
                
                <input type="text" name="{{$name['flowNextCount']}}" style="width:50px; text-align:right;" value="{{old($column['flowNextCount'], $value['flowNextCount']) ?? 1}}" />
                &nbsp;{{exmtrans('workflow.upper_user')}}
            </label>
        </div>

        <div class="radio icheck">
            <label>
                <input type="radio" name="{{$name['flowNextType']}}" value="all" class="minimal {{$class['flowNextType']}}" {{ ($option == old($column['flowNextType'], $value['flowNextType'])) || ($value['flowNextType'] === 'all') ?'checked':'' }} />&nbsp;
                &nbsp;
                {{exmtrans('workflow.all_user')}}
            </label>
        </div>

        @include('admin::form.help-block')

    </div>
</div>
