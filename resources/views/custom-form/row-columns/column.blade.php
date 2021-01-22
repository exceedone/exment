<div class="custom_form_area col-sm-{{$gridWidth ?? 3}}">
    <div class="custom_form_area_inner">
        <p class="text-right custom_form_area_header">
            <a href="javascript:void(0);" class="config-icon delete">
                <i class="fa fa-trash"></i>
            </a>
        </p>
        <div class="draggables" data-connecttosortable="row_{{$custom_form_block['form_block_type']}}_{{$custom_form_block['form_block_target_table_id']}}">
            @foreach($custom_form_item_column['custom_form_columns'] ?? [] as $custom_form_column)
                @include("exment::custom-form.form-item", ['custom_form_column' => $custom_form_column, 'suggest' => false]) 
            @endforeach
        </div>
    </div>
</div>