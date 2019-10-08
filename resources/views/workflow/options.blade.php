<div class="{{$viewClass['form-group']}} {!! ($errors->has($errorKey['comment_type'].'comment_type')) ? 'has-error' : ''  !!}">

<label for="{{$id['comment_type']}}" class="{{$viewClass['label']}} control-label">{{$label}}</label>

    <div class="{{$viewClass['field']}}">

        @include('admin::form.error')


        <label for="{{$id['flow_next_type']}}" class="control-label small asterisk">{{exmtrans('workflow.flow_next_type')}}</label>
        
        <div class="radio icheck">
            <label>
                <input type="radio" name="{{$name['flow_next_type']}}" value="some" class="minimal {{$class['flow_next_type']}}" {{ (old($column['flow_next_type'], $value['flow_next_type']) ?? 'some' === 'some') ?'checked':'' }} />&nbsp;
                
                <input type="text" name="{{$name['flow_next_count']}}" style="width:50px; text-align:right;" value="{{old($column['flow_next_count'], $value['flow_next_count']) ?? 1}}" required />
                &nbsp;{{exmtrans('workflow.upper_user')}}
            </label>
        </div>

        <div class="radio icheck">
            <label>
                <input type="radio" name="{{$name['flow_next_type']}}" value="all" class="minimal {{$class['flow_next_type']}}" {{ (old($column['flow_next_type'], $value['flow_next_type']) === 'all') ?'checked':'' }} required />&nbsp;
                &nbsp;
                {{exmtrans('workflow.all_user')}}
            </label>
        </div>



        <label for="{{$id['comment_type']}}" class="control-label small asterisk">{{exmtrans('common.comment')}}</label>
        <select class="form-control {{$class['comment_type']}}" style="width: 100%;" name="{{$name['comment_type']}}" {!! $attributes !!} required >
            <option value=""></option>
            @foreach($optionsCommentType as $select => $option)
                <option value="{{$select}}" {{ $select == (old($column['comment_type'], $value['comment_type']) ?? $defaultCommentType) ?'selected':'' }}>{{$option}}</option>
            @endforeach
        </select>


        @if($index !== 0)
        <label for="{{$id['rejectAction']}}" class="control-label small">却下フロー</label>
        <div class="">
            <input type="checkbox" name="{{$name['rejectAction']}}" value="1" class="{{$class['rejectAction']}}" {{ old($column['rejectAction'], $value['rejectAction']) == '1' ? 'checked' : '' }} />
        </div>
        @endif

        @include('admin::form.help-block')

    </div>
</div>
