<div class="{{$viewClass['form-group']}} {!! ($errors->has($errorKey['commentType'].'commentType')) ? 'has-error' : ''  !!}">

<label for="{{$id['commentType']}}" class="{{$viewClass['label']}} control-label">{{$label}}</label>

    <div class="{{$viewClass['field']}}">

        @include('admin::form.error')


        <label for="{{$id['flowNextType']}}" class="control-label small asterisk">{{exmtrans('workflow.flow_next_type')}}</label>
        
        <div class="radio icheck">
            <label>
                <input type="radio" name="{{$name['flowNextType']}}" value="some" class="minimal {{$class['flowNextType']}}" {{ (old($column['flowNextType'], $value['flowNextType']) ?? 'some' === 'some') ?'checked':'' }} />&nbsp;
                
                <input type="text" name="{{$name['flowNextCount']}}" style="width:50px; text-align:right;" value="{{old($column['flowNextCount'], $value['flowNextCount']) ?? 1}}" required />
                &nbsp;{{exmtrans('workflow.upper_user')}}
            </label>
        </div>

        <div class="radio icheck">
            <label>
                <input type="radio" name="{{$name['flowNextType']}}" value="all" class="minimal {{$class['flowNextType']}}" {{ (old($column['flowNextType'], $value['flowNextType']) === 'all') ?'checked':'' }} required />&nbsp;
                &nbsp;
                {{exmtrans('workflow.all_user')}}
            </label>
        </div>



        <label for="{{$id['commentType']}}" class="control-label small asterisk">{{exmtrans('common.comment')}}</label>
        <select class="form-control {{$class['commentType']}}" style="width: 100%;" name="{{$name['commentType']}}" {!! $attributes !!} required >
            <option value=""></option>
            @foreach($optionsCommentType as $select => $option)
                <option value="{{$select}}" {{ $select == (old($column['commentType'], $value['commentType']) ?? $defaultCommentType) ?'selected':'' }}>{{$option}}</option>
            @endforeach
        </select>



        <label for="{{$id['rejectAction']}}" class="control-label small">却下フロー</label>
        <div class="">
            <input type="checkbox" name="{{$name['rejectAction']}}" value="1" class="{{$class['rejectAction']}}" {{ old($column['rejectAction'], $value['rejectAction']) == '1' ? 'checked' : '' }} />
        </div>

        @include('admin::form.help-block')

    </div>
</div>
