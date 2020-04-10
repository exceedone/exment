<div class="{{$viewClass['form-group']}} {!! ($errors->has($errorKey['comment_type'].'comment_type')) ? 'has-error' : ''  !!}">

<label for="{{$id['comment_type']}}" class="{{$viewClass['label']}} control-label">{{$label}}</label>

    <div class="{{$viewClass['field']}}">

        @include('admin::form.error')

        <div class="workflow_table_item">
            <label for="{{$id['flow_next_type']}}" class="control-label small asterisk">
                {{exmtrans('workflow.flow_next_type')}}
                <i class="fa fa-info-circle" data-help-text="{{exmtrans('workflow.help.flow_next_type')}}" data-help-title="{{exmtrans('workflow.flow_next_type')}}"></i>
            </label>
            
            <div class="radio icheck">
                <label>
                    <input type="radio" name="{{$name['flow_next_type']}}" value="some" class="minimal {{$class['flow_next_type']}}" {{ (old($column['flow_next_type'], array_get($value, 'flow_next_type')) ?? 'some' === 'some') ?'checked':'' }} />&nbsp;
                    
                    <input type="text" name="{{$name['flow_next_count']}}" style="width:50px; text-align:right;" value="{{old($column['flow_next_count'], array_get($value, 'flow_next_count')) ?? 1}}" required />
                    &nbsp;{{exmtrans('workflow.upper_user')}}
                </label>
            </div>

            <div class="radio icheck">
                <label>
                    <input type="radio" name="{{$name['flow_next_type']}}" value="all" class="minimal {{$class['flow_next_type']}}" {{ (old($column['flow_next_type'], array_get($value, 'flow_next_type')) === 'all') ?'checked':'' }} required />&nbsp;
                    &nbsp;
                    {{exmtrans('workflow.all_user')}}
                </label>
            </div>
        </div>


        <div class="workflow_table_item">
            <label for="{{$id['comment_type']}}" class="control-label small asterisk">{{exmtrans('common.comment')}}</label>
            <select class="form-control {{$class['comment_type']}}" style="width: 100%;" name="{{$name['comment_type']}}" {!! $attributes !!} required >
                <option value=""></option>
                @foreach($optionsCommentType as $select => $option)
                    <option value="{{$select}}" {{ $select == (old($column['comment_type'], array_get($value, 'comment_type')) ?? $defaultCommentType) ?'selected':'' }}>{{$option}}</option>
                @endforeach
            </select>
        </div>


        @if($index !== 0)
        <div class="workflow_table_item">
            <label for="{{$id['ignore_work']}}" class="control-label small">
                {{exmtrans('workflow.ignore_work')}}
                <i class="fa fa-info-circle" data-help-text="{{exmtrans('workflow.help.ignore_work')}}" data-help-title="{{exmtrans('workflow.ignore_work')}}"></i>
            </label>

            <div class="">
                <input type="checkbox" name="{{$name['ignore_work']}}" value="1" class="{{$class['ignore_work']}}" {{ old($column['ignore_work'], array_get($value, 'ignore_work')) == '1' ? 'checked' : '' }} />
            </div>
        </div>
        @endif

        @include('admin::form.help-block')

    </div>
</div>
