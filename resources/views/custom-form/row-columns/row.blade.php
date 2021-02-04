<div class="row row-eq-height row_{{$custom_form_block['form_block_type']}}_{{$custom_form_block['form_block_target_table_id']}}">
    @foreach($row['columns'] ?? [] as $custom_form_item_column)
        @include('exment::custom-form.row-columns.column', ['gridWidth' => $custom_form_item_column['gridWidth'] ?? 3])
    @endforeach
    @include('exment::custom-form.row-columns.addarea', ['isShow' => $custom_form_item_row['isShowAddButton'] ?? false])
</div>