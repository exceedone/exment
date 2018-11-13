<?php

namespace Exceedone\Exment\Model;
use Encore\Admin\Facades\Admin;
use Illuminate\Http\Request as Req;

class CustomForm extends ModelBase
{
    use Traits\AutoSUuidTrait;
    use Traits\DefaultFlgTrait;
    use \Illuminate\Database\Eloquent\SoftDeletes;

    public function custom_table(){
        return $this->belongsTo(CustomTable::class, 'custom_table_id');
    }

    public function custom_form_blocks(){
        return $this->hasMany(CustomFormBlock::class, 'custom_form_id');
    }
    
    public function custom_form_columns(){
        return $this->hasManyThrough(CustomFormColumn::class, CustomFormBlock::class, 'custom_form_id', 'custom_form_block_id');
    }

    
    /**
     * get default view using table
     */
    public static function getDefault($tableObj){
        $user = Admin::user();
        $tableObj = CustomTable::getEloquent($tableObj);
        // get request
        $request = Req::capture();

        // get form using query
        if(!is_null($request->input('form'))){
            // if query has form id, set form.
            $suuid = $request->input('form');
            $form = static::findBySuuid($suuid);

            // set suuid
            if (!is_null($user)) {
                $user->setSettingValue(implode(".", [Define::USER_SETTING_FORM, $tableObj->table_name]), $suuid);
            }
        }
        // if url doesn't contain form query, get form user setting.
        if(!isset($form) && !is_null($user)){
            // get suuid
            $suuid = $user->getSettingValue(implode(".", [Define::USER_SETTING_FORM, $tableObj->table_name]));
            $form = CustomForm::findBySuuid($suuid);
        }

        // if not exists, get default.
        if(!isset($form)){
            $form = $tableObj->custom_forms()->where('default_flg', true)->first();
        }
        // if not exists, get first.
        if(!isset($form)){
            $form = $tableObj->custom_forms()->first();
        }
        
        // if form doesn't contain for target table, create form.
        if(!isset($form)){
            $form = new CustomForm;
            $form->custom_table_id = $tableObj->id;
            $form->form_view_name = exmtrans('custom_form.default_form_name');
            $form->saveOrFail();
            $form = $form;

            // re-get form
            $form = static::find($form->id);
        }

        // get form block
        $form_block = $form->custom_form_blocks()
            ->where('form_block_type', Define::CUSTOM_FORM_BLOCK_TYPE_DEFAULT)
            ->first();
        if(!isset($form_block)){
            // Create CustomFormBlock as default
            $form_block = new CustomFormBlock;
            $form_block->form_block_type = Define::CUSTOM_FORM_BLOCK_TYPE_DEFAULT;
            $form_block->form_block_target_table_id = $tableObj->id;
            $form_block->available = true;
            $form->custom_form_blocks()->save($form_block);
        }   

        // if target form doesn't have columns, add columns for search_enabled columns.
        if(!isset($form->custom_form_columns) || count($form->custom_form_columns) == 0){
            $form_columns = [];
            $search_enabled_columns = getSearchEnabledColumns($tableObj->table_name);

            // get target block as default.
            $form_block = $form->custom_form_blocks()
                ->where('form_block_type', Define::CUSTOM_FORM_BLOCK_TYPE_DEFAULT)
                ->first();            
            // loop for search_enabled columns, and add form.
            foreach ($search_enabled_columns as $index => $search_enabled_column)
            {
                $form_column = new CustomFormColumn;
                $form_column->custom_form_block_id = $form_block->id;
                $form_column->form_column_type = Define::CUSTOM_FORM_COLUMN_TYPE_COLUMN;
                $form_column->form_column_target_id = array_get($search_enabled_column, 'id');
                $form_column->order = $index+1;
                array_push($form_columns, $form_column);
            }
            $form_block->custom_form_columns()->saveMany($form_columns);
            
            // re-get form
            $form = static::find($form->id);
        }

        return $form;
    }
    
    
    public function deletingChildren(){
        foreach($this->custom_form_blocks as $item){
            $item->custom_form_columns()->delete();
        }
    }

    protected static function boot() {
        parent::boot();
        
        static::creating(function($model) {
            $model->setDefaultFlgInTable();
        });
        static::updating(function($model) {
            $model->setDefaultFlgInTable();
        });
        
        static::deleting(function($model) {
            $model->deletingChildren();
            $model->custom_form_blocks()->delete();
            $model->custom_form_block_target_tables()->delete();
        });
    }
}
