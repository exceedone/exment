<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\RelationType;
use Encore\Admin\Facades\Admin;

use Illuminate\Database\Eloquent\Collection;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\RoleValue;
use Carbon\Carbon;

class CustomValue extends ModelBase
{
    use Traits\AutoSUuidTrait;
    use Traits\DatabaseJsonTrait;
    use \Illuminate\Database\Eloquent\SoftDeletes;
    use \Exceedone\Exment\Revisionable\RevisionableTrait;

    protected $casts = ['value' => 'json'];
    protected $appends = ['label'];
    protected $hidden = ['laravel_admin_escape'];
    protected $keepRevisionOf = ['value'];
    /**
     * remove_file_columns.
     * default flow, if file column is empty, set original value.
     */
    protected $remove_file_columns = [];

    /**
     * saved notify.
     * if false, don't notify
     */
    protected $saved_notify = true;
    
    public function getLabelAttribute()
    {
        return $this->getLabel();
    }

    public function getCustomTableAttribute()
    {
        // return resuly using cache
        return System::requestSession('custom_table_' . $this->custom_table_name, function()
        {
            return CustomTable::getEloquent($this->custom_table_name);
        });
    }

    // user value_authoritable. it's all role data. only filter morph_type
    public function value_authoritable_users()
    {
        return $this->morphToMany(getModelName(SystemTableName::USER), 'morph', 'value_authoritable', 'morph_id', 'related_id')
            ->withPivot('related_id', 'related_type')
            ->wherePivot('related_type', SystemTableName::USER)
            ;
    }

    // user value_authoritable. it's all role data. only filter morph_type
    public function value_authoritable_organizations()
    {
        return $this->morphToMany(getModelName(SystemTableName::ORGANIZATION), 'morph', 'value_authoritable', 'morph_id', 'related_id')
            ->withPivot('related_id', 'related_type')
            ->wherePivot('related_type', SystemTableName::ORGANIZATION)
            ;
    }

    public function parent_custom_value()
    {
        return $this->morphTo();
    }
    /**
     * get or set remove_file_columns
     */
    public function remove_file_columns($key = null)
    {
        // get
        if (!isset($key)) {
            return $this->remove_file_columns;
        }

        // set
        $this->remove_file_columns[] = $key;
        return $this;
    }
    
    public function saved_notify($disable_saved_notify)
    {
        $this->saved_notify = $disable_saved_notify;
        return $this;
    }

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($model) {
            // re-get field data --------------------------------------------------
            $model->regetOriginalData();
        });
        static::saved(function ($model) {
            // set auto format
            $model->setAutoNumber();
            $model->setValueAuthoritable();
        });
        static::created(function ($model) {
            // send notify
            $model->notify(true);
        });
        static::updated(function ($model) {
            // send notify
            $model->notify(false);
        });
        
        static::deleting(function ($model) {
            $model->deleteRelationValues();
        });

        static::addGlobalScope(new CustomValueModelScope);
    }


    // re-set field data --------------------------------------------------
    // if user update form and save, but other field remove if not conatins form field, so re-set field before update
    protected function regetOriginalData()
    {
        ///// saving event for image, file event
        // https://github.com/z-song/laravel-admin/issues/1024
        // because on value edit display, if before upload file and not upload again, don't post value.
        $value = $this->value;
        $original = json_decode($this->getOriginal('value'), true);
        // get  columns
        $file_columns = $this->custom_table
            ->custom_columns
            ->pluck('column_name')
            ->toArray();

        // loop columns
        $update_flg = false;
        foreach ($file_columns as $file_column) {

            // if not key, set from original
            if (!array_key_exists($file_column, $value)) {
                // if column has $remove_file_columns, continue.
                // property "$remove_file_columns" uses user wants to delete file
                if (in_array($file_column, $this->remove_file_columns())) {
                    continue;
                }

                if (array_key_value_exists($file_column, $original)) {
                    $value[$file_column] = array_get($original, $file_column);
                    $update_flg = true;
                }
            }
        }

        // array_forget if $v is null
        // if not execute this, mysql column "virtual" returns string "null".
        foreach($value as $k => $v){
            if (is_null($v)) {
                $update_flg = true;
                array_forget($value, $k);
            }
        }

        // if update
        if ($update_flg) {
            $this->setAttribute('value', $value);
        }
    }

    /**
     * set auto number
     */
    protected function setAutoNumber()
    {
        ///// saving event for image, file event
        // https://github.com/z-song/laravel-admin/issues/1024
        // because on value edit display, if before upload file and not upload again, don't post value.
        $value = $this->value;
        $id = $this->id;
        // get image and file columns
        $columns = $this->custom_table
            ->custom_columns
            ->all();

        $update_flg = false;
        // loop columns
        foreach ($columns as $custom_column) {
            // custom column
            $column_name = array_get($custom_column, 'column_name');
            switch (array_get($custom_column, 'column_type')) {
                // if column type is auto_number, set auto number.
                case ColumnType::AUTO_NUMBER:
                    // already set value, break
                    if (!is_null($this->getValue($column_name))) {
                        break;
                    }
                    $options = $custom_column->options;
                    if (!isset($options)) {
                        break;
                    }
                    
                    if (array_get($options, 'auto_number_type') == 'format') {
                        $auto_number = $this->createAutoNumberFormat($id, $options);
                    }
                    // if auto_number_type is random25, set value
                    elseif (array_get($options, 'auto_number_type') == 'random25') {
                        $auto_number = make_licensecode();
                    }
                    // if auto_number_type is UUID, set value
                    elseif (array_get($options, 'auto_number_type') == 'random32') {
                        $auto_number = make_uuid();
                    }

                    if (isset($auto_number)) {
                        $this->setValue($column_name, $auto_number);
                        $update_flg = true;
                    }
                    break;
            }
        }
        // if update
        if ($update_flg) {
            $this->save();
        }
    }

    /**
     * set value_authoritable
     */
    public function setValueAuthoritable()
    {
        $table_name = $this->custom_table->table_name;
        if(in_array($table_name, SystemTableName::SYSTEM_TABLE_NAME_IGNORE_SAVED_AUTHORITY())){
            return;
        }
        if($this->value_authoritable_users()->count() > 0 || $this->value_authoritable_organizations()->count() > 0){
            return;
        }
        $user = \Exment::user();
        if(!isset($user)){
            return;
        }

        // get role editable value
        $role = Role::where('role_type', RoleType::VALUE)->whereIn('permissions->'.RoleValue::CUSTOM_VALUE_EDIT, [1, "1"])
            ->first();
        // set user
        if(!isset($role)){
            return;
        }

        \DB::table(SystemTableName::VALUE_AUTHORITABLE)->insert(
            [
                'related_id' => $user->base_user_id,
                'related_type' => SystemTableName::USER,
                'morph_id' => $this->id,
                'morph_type' => $table_name,
                'role_id' => $role->id,
            ]
        );
    }

    
    
    // notify user --------------------------------------------------
    protected function notify($create = true)
    {
        // if $saved_notify is false, return
        if($this->saved_notify === false){
            return;
        }

        $notifies = Notify::where('notify_trigger', NotifyTrigger::CREATE_UPDATE_DATA)
            ->where('custom_table_id', $this->custom_table->id)
            ->get();

        // loop for $notifies
        foreach ($notifies as $notify) {
            $notify->notifyCreateUpdateUser($this, $create);
        }
    }

    /**
     * Create Auto Number value using format.
     */
    protected function createAutoNumberFormat($id, $options)
    {
        // get format
        $format = array_get($options, "auto_number_format");
        try {
            // check string
            preg_match_all('/'.Define::RULES_REGEX_VALUE_FORMAT.'/', $format, $matches);
            if (isset($matches)) {
                // loop for matches. because we want to get inner {}, loop $matches[1].
                for ($i = 0; $i < count($matches[1]); $i++) {
                    try {
                        $match = strtolower($matches[1][$i]);
                    
                        // get length
                        $length_array = explode(":", $match);
                        
                        ///// id
                        if (strpos($match, "id") !== false) {
                            // replace add zero using id.
                            if (count($length_array) > 1) {
                                $id_string = sprintf('%0'.$length_array[1].'d', $id);
                            } else {
                                $id_string = $id;
                            }
                            $format = str_replace($matches[0][$i], $id_string, $format);
                        }

                        ///// Year
                        elseif (strpos($match, "y") !== false) {
                            $str = Carbon::now()->year;
                            $format = str_replace($matches[0][$i], $str, $format);
                        }

                        ///// Month
                        elseif (strpos($match, "m") !== false) {
                            $str = Carbon::now()->month;
                            // if user input length
                            if (count($length_array) > 1) {
                                $length = $length_array[1];
                            }
                            // default 2
                            else {
                                $length = 2;
                            }
                            $format = str_replace($matches[0][$i], sprintf('%0'.$length.'d', $str), $format);
                        }
                    
                        ///// Day
                        elseif (strpos($match, "d") !== false) {
                            $str = Carbon::now()->day;
                            // if user input length
                            if (count($length_array) > 1) {
                                $length = $length_array[1];
                            }
                            // default 2
                            else {
                                $length = 2;
                            }
                            $format = str_replace($matches[0][$i], sprintf('%0'.$length.'d', $str), $format);
                        }

                        ///// value
                        elseif (strpos($match, "value") !== false) {
                            // get value from model
                            if (count($length_array) <= 1) {
                                $str = '';
                            } else {
                                $str = $this->getValue($length_array);
                            }
                            $format = str_replace($matches[0][$i], $str, $format);
                        }
                    } catch (Exception $e) {
                    }
                }
            }
        } catch (Exception $e) {
        }
        return $format;
    }

    /**
     * delete relation if record delete
     */
    protected function deleteRelationValues(){
        $custom_table = $this->custom_table;
        // delete custom relation is 1:n value
        $relations = CustomRelation::getRelationsByParent($custom_table, RelationType::ONE_TO_MANY);
        // loop relations
        foreach ($relations as $relation) {
            $child_table = $relation->child_custom_table;
            // find keys
            getModelName($child_table)
                ::where('parent_id', $this->id)
                ->where('parent_type', $custom_table->table_name)
                ->delete();
        }
        
        // delete custom relation is n:n value
        $relations = CustomRelation::getRelationsByParent($custom_table, RelationType::MANY_TO_MANY);
        // loop relations
        foreach ($relations as $relation) {
            // ge pivot table
            $pivot_name = $relation->getRelationName();
            
            // find keys and delete
            \DB::table($pivot_name)
                ->where('parent_id', $this->id)
                ->delete();
        }
        
        // delete custom relation is n:n value (for children)
        $relations = CustomRelation::getRelationsByChild($custom_table, RelationType::MANY_TO_MANY);
        // loop relations
        foreach ($relations as $relation) {
            // ge pivot table
            $pivot_name = $relation->getRelationName();
            
            // find keys and delete
            \DB::table($pivot_name)
                ->where('child_id', $this->id)
                ->delete();
        }
    }
    
    /**
     * get Authoritable values.
     * this function selects value_authoritable, and get all values.
     */
    public function getAuthoritable($related_type)
    {
        if ($related_type == SystemTableName::USER) {
            $query = $this
            ->value_authoritable_users()
            ->where('related_id', \Exment::user()->base_user_id);
        } elseif ($related_type == SystemTableName::ORGANIZATION) {
            $query = $this
            ->value_authoritable_organizations()
            ->whereIn('related_id', \Exment::user()->getOrganizationIds());
        }

        return $query->get();
    }

    public function setValue($key, $val = null, $forgetIfNull = false)
    {
        return $this->setJson('value', $key, $val, $forgetIfNull);
    }
    
    public function getValue($column, $label = false, $options = [])
    {
        $options = array_merge(
            [
                'format' => null,
                'disable_currency_symbol' => false,
            ], $options
        );
        $custom_table = $this->custom_table;
        if (is_null($column)) {
            return null;
        }

        // if $column is string and  and contains comma
        if (is_string($column) && str_contains($column, ',')) {
            ///// getting value Recursively
            // split comma
            $columns = explode(",", $column);
            // if $columns count >= 2, loop columns
            if (count($columns) >= 2) {
                $loop_value = $this;
                foreach ($columns as $k => $c) {
                    $lastIndex = ($k == count($columns) - 1);
                    // if $k is not last index, $loop_label is false(because using CustomValue Object)
                    if (!$lastIndex) {
                        $loop_label = false;
                    }
                    // if last index, $loop_label is called $label
                    else {
                        $loop_label = $label;
                    }
                    // get value using $c
                    $loop_value = $loop_value->getValue($c, $loop_label);
                    // if null, return
                    if (is_null($loop_value)) {
                        return null;
                    }
                    
                    // if last index, return value
                    if ($lastIndex) {
                        return $loop_value;
                    }
                    
                    // get custom table. if CustomValue
                    if (!($loop_value instanceof CustomValue)) {
                        return null;
                    }
                }
                return $loop_value;
            }
            else {
                $column = $columns[0];
            }
        }

        ///// get custom column
        // if string
        $column = CustomColumn::getEloquent($column, $custom_table);
        if (is_null($column)) {
            return null;
        }

        // get database value
        $val = array_get($this, "value.{$column->column_name}");
        if (is_null($val)) {
            return null;
        }

        $column_type = array_get($column, 'column_type');
        // calcurate  --------------------------------------------------
        if (in_array($column_type, [ColumnType::DECIMAL, ColumnType::CURRENCY])) {
            $val = parseFloat($val);
            if (array_has($column, 'options.decimal_digit')) {
                $digit = intval(array_get($column, 'options.decimal_digit'));
                $val = floor($val * pow(10, $digit)) / pow(10, $digit);
            }
        }

        // return finally value --------------------------------------------------
        // get value as select
        // get value as select_valtext
        if (in_array($column_type, [ColumnType::SELECT, ColumnType::SELECT_VALTEXT])) {
            $array_get_key = $column_type == 'select' ? 'options.select_item' : 'options.select_item_valtext';
            $select_item = array_get($column, $array_get_key);
            $select_options = CustomColumn::getEloquent($column, $custom_table)->createSelectOptions();
            if (!array_keys_exists($val, $select_options)) {
                return null;
            }

            // if $val is array
            $multiple = true;
            if (!is_array($val)) {
                $val = [$val];
                $multiple = false;
            }
            // switch column_type and get return value
            $returns = [];
            switch ($column_type) {
                case ColumnType::SELECT:
                    $returns = $val;
                    break;
                case ColumnType::SELECT_VALTEXT:
                    // loop keyvalue
                    foreach ($val as $v) {
                        // set whether $label
                        $returns[] = $label ? array_get($select_options, $v) : $v;
                    }
                    break;
            }
            if ($multiple) {
                return $label ? implode(exmtrans('common.separate_word'), $returns) : $returns;
            } else {
                return $returns[0];
            }
        }

        // get value as select_table
        elseif (in_array($column_type, [ColumnType::SELECT_TABLE, ColumnType::USER, ColumnType::ORGANIZATION])) {
            // get target table
            $target_table_key = null;
            if ($column_type == ColumnType::SELECT_TABLE) {
                $target_table_key = array_get($column, 'options.select_target_table');
            } elseif (in_array($column_type, [SystemTableName::USER, SystemTableName::ORGANIZATION])) {
                $target_table_key = $column_type;
            }
            $target_table = CustomTable::getEloquent($target_table_key);

            $model = getModelName(array_get($target_table, 'table_name'))::find($val);
            if (is_null($model)) {
                return null;
            }
            if ($label === false) {
                return $model;
            }
            
            // if $model is array multiple, set as array
            if (!($model instanceof Collection)) {
                $model = [$model];
            }

            $labels = [];
            foreach ($model as $m) {
                if (is_null($m)) {
                    continue;
                }
                
                // get label column
                // if label is true, return getLabel
                if ($label === true) {
                    $labels[] = $m->label;
                }
                // if label is selecting column name, get target label
                elseif (is_string($label)) {
                    $labels[] = CustomColumn::where('custom_table_id', $target_table['id'])->where('column_name', $label)->first();
                }
            }
            return implode(exmtrans('common.separate_word'), $labels);
        } elseif (in_array($column_type, [ColumnType::FILE, ColumnType::IMAGE])) {
            // get file
            if ($label !== true) {
                $file = File::getFile($val);
                return $file;
            }
            return $val;
        }
        // yesno
        elseif (in_array($column_type, [ColumnType::YESNO])) {
            if ($label !== true) {
                return $val;
            }
            // convert label
            return boolval($val) ? 'YES' : 'NO';
        }
        // boolean
        elseif (in_array($column_type, [ColumnType::BOOLEAN])) {
            if ($label !== true) {
                return $val;
            }
            // convert label
            // check matched true and false value
            if (array_get($column, 'options.true_value') == $val) {
                return array_get($column, 'options.true_label');
            } elseif (array_get($column, 'options.false_value') == $val) {
                return array_get($column, 'options.false_label');
            }
            return null;
        }
        // currency
        elseif (in_array($column_type, [ColumnType::CURRENCY])) {
            // if not label, return
            if ($label !== true) {
                return $val;
            }
            if (boolval(array_get($column, 'options.number_format')) 
                && is_numeric($val) 
                && !boolval(array_get($options, 'disable_number_format')))
            {
                $val = number_format($val);
            }
            if(boolval(array_get($options, 'disable_currency_symbol'))){
                return $val;
            }
            // get symbol
            $symbol = array_get($column, 'options.currency_symbol');
            return getCurrencySymbolLabel($symbol, $val);
        }
        // datetime, date
        elseif (in_array($column_type, [ColumnType::DATETIME, ColumnType::DATE])) {
            // if not empty format, using carbon
            $format = array_get($options, 'format');
            if (!is_nullorempty($format)) {
                return (new \Carbon\Carbon($val))->format($format) ?? null;
            }
            // else, return
            return $val;
        }
        else {
            // if not label, return
            if ($label !== true) {
                return $val;
            }
            if (boolval(array_get($column, 'options.number_format')) && is_numeric($val)) {
                $val = number_format($val);
            }
            return $val;
        }
    }
        
    /**
     * Get vustom_value's label 
     * @param CustomValue $custom_value
     * @return string
     */
    public function getLabel()
    {
        $custom_table = $this->custom_table;

        $key = 'custom_table_use_label_flg_' . $this->custom_table_name;
        $columns = System::requestSession($key, function() use($custom_table){
            return $custom_table
            ->custom_columns()
            ->useLabelFlg()
            ->get();
        });

        if (!isset($columns) || count($columns) == 0) {
            $columns = [$custom_table->custom_columns->first()];
        }

        // loop for columns and get value
        $labels = [];
        foreach ($columns as $column) {
            if (!isset($column)) {
                continue;
            }
            $label = $this->getValue($column, true);
            if (!isset($label)) {
                continue;
            }
            $labels[] = $label;
        }
        if(count($labels) == 0){
            return strval($this->id);
        }

        return implode(' ', $labels);
    }

    /**
     * get target custom_value's self link url
     */
    public function getUrl($options = [])
    {
        // options is boolean, tag is true
        if(is_bool($options)){
            $options = ['tag' => true];
        }
        $options = array_merge(
            [
                'tag' => false,
                'uri' => null,
                'list' => false,
                'external-link' => false,
                'modal' => true,
            ]
            , $options
        );
        $tag = boolval($options['tag']);

        // if this table is document, create target blank link
        if($this->custom_table->table_name == SystemTableName::DOCUMENT){
            $url = admin_urls('files', $this->getValue('file_uuid', true));
            if (!$tag) {
                return $url;
            }
            $label = esc_html($this->getValue('document_name'));
            return "<a href='$url' target='_blank'>$label</a>";
        }
        $url = admin_urls('data', $this->custom_table->table_name);
        if(!boolval($options['list'])){
            $url = url_join($url, $this->id);
        }
        
        if(isset($options['uri'])){
            $url = url_join($url, $options['uri']);
        }
        if (!$tag) {
            return $url;
        }
        if(boolval($options['external-link'])){
            $label = '<i class="fa fa-external-link" aria-hidden="true"></i>';
        }else{
            $label = esc_html($this->getLabel());
        }

        if (boolval($options['modal'])) {
            $url .= '?modal=1';
            $href = 'javascript:void(0);';
            $widgetmodal_url = " data-widgetmodal_url='$url'";
        }else{
            $href = $url;
            $widgetmodal_url = null;
        }

        return "<a href='$href'$widgetmodal_url>$label</a>";
    }

    /**
     * Get url for column_type is url, select_table.
     * @param CustomValue $custom_value
     * @param CustomColumn $column
     * @return string
     */
    public function getColumnUrl($column, $tag = false)
    {
        $url = null;
        $value = esc_html($this->getValue($column, true));
        switch ($column->column_type) {
            case ColumnType::URL:
                $url = $this->getValue($column);
                if (!$tag) {
                    return $url;
                }
                return "<a href='{$url}' target='_blank'>$value</a>";
            case ColumnType::SELECT_TABLE:
            case ColumnType::USER:
            case ColumnType::ORGANIZATION:
                $target_value = $this->getValue($column);
                    
                // if $target_value is not array multiple, set as array
                if (!($target_value instanceof Collection)) {
                    $target_value = [$target_value];
                }
                $urls = [];
                foreach ($target_value as $m) {
                    if (is_null($m)) {
                        continue;
                    }

                    $id = $m->id ?? null;
                    if (!isset($id)) {
                        continue;
                    }
                    // create url
                    $urls[] = $m->getUrl($tag);
                }
                return implode(exmtrans('common.separate_word'), $urls);
        }
 
        return null;
    }

    /**
     * get parent value
     */
    public function getParentValue($isonly_label = false)
    {
        $model = getModelName($this->parent_type)::find($this->parent_id);
        if (!$isonly_label) {
            return $model ?? null;
        }
        return $model->label ?? null;
    }
    /**
     * Get Custom children value summary
     */
    public function getSum($custom_column) {
        $name = $custom_column->indexEnabled() ? $custom_column->getIndexColumnName() : 'value->'.array_get($custom_column, 'column_name');

        if(!isset($name)){
            return 0;
        }
        return $this->getChildrenValues($custom_column, true)
            ->sum($name);
    }

    /**
     * Get Custom children Value.
     * v1.3.0 changes ... get children values using relation or select_table
     */
    public function getChildrenValues($relation, $returnBuilder = false)
    {
        // first, get children values as relation
        if($relation instanceof CustomColumn){
            // get custom column as array
            // target column is select table and has index, get index name
            if(ColumnType::isSelectTable($relation->column_type) && $relation->indexEnabled()){
                $index_name = $relation->getIndexColumnName();
                // get children values where this id
                $query = getModelName(CustomTable::getEloquent($relation))
                    ::where($index_name, $this->id);
                    return $returnBuilder ? $query : $query->get();
            }
        }
    
        // get custom column as array
        $child_table = CustomTable::getEloquent($relation);
        $pivot_table_name = CustomRelation::getRelationNameByTables($this->custom_table, $child_table);

        if(isset($pivot_table_name)){
            return $returnBuilder ? $this->{$pivot_table_name}() : $this->{$pivot_table_name};
        }
        
        return null;
    }

    /**
     * set revision data
     */
    public function setRevision($revision_suuid){
        $revision_value = $this->revisionHistory()->where('suuid', $revision_suuid)->first()->new_value;
        if(is_json($revision_value)){
            $revision_value = \json_decode($revision_value, true);
        }
        $this->value = $revision_value;
        return $this;
    }
}
