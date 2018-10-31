<?php

namespace Exceedone\Exment\Model;


class CustomCopy extends ModelBase
{
    use \Illuminate\Database\Eloquent\SoftDeletes;
    use AutoSUuid;
    
    protected $casts = ['options' => 'json'];

    public function from_custom_table(){
        return $this->belongsTo(CustomTable::class, 'from_custom_table_id');
    }

    public function to_custom_table(){
        return $this->belongsTo(CustomTable::class, 'to_custom_table_id');
    }

    public function custom_copy_columns(){
        return $this->hasMany(CustomCopyColumn::class, 'custom_copy_id')
        ->where('custom_copy_type', 'default');
    }

    public function custom_copy_input_columns(){
        return $this->hasMany(CustomCopyColumn::class, 'custom_copy_id')
        ->where('custom_copy_type', 'input');
    }

    /**
     * execute data copy
     */
    public function execute($from_custom_value){
        // get to_custom_value model
        $to_modelname = getModelName($this->to_custom_table);
        $to_custom_value = new $to_modelname;

        // loop for custom_copy_columns
        foreach($this->custom_copy_columns as $custom_copy_column){
            // get column
            $custom_column = $custom_copy_column->custom_column;
            // get value. (NOT use getValue function because don't want convert value. get $custom_value->value['column'] value.)
            $val = array_get($from_custom_value, "value.{$custom_column->column_name}");
            $to_custom_value->setValue($custom_column->column_name, $val);
        }

        // save
        $to_custom_value->saveOrFail();

        return ([
            'status'  => true,
            'message' => 'Copy Success!!', //TODO:trans
        ]);
    }
}
