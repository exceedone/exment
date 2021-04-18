@foreach($fieldGroups as $fieldRow)
    <div class="row">
        @foreach($fieldRow['columns'] as $fieldColumn)
            <div class="gridsow-columns col-md-{{array_get($fieldColumn, 'col_md', 12)}}">
            @foreach($fieldColumn['fields'] as $field)
                <div class="row">
                    <div class="gridsow-field col-sm-{{array_get($field, 'field_sm', 12)}} col-sm-offset-{{array_get($field, 'field_offset', 0)}}">
                        {!! $field['field']->render() !!}
                    </div>
                </div>
            @endforeach
        </div>
        @endforeach
    </div>
@endforeach