{{ Form::hidden("{$custom_form_block['header_name']}[{$param_name}]", array_get($custom_form_block, $param_name), array_merge(['class' => $param_name], $param_parameters ?? []))}}
