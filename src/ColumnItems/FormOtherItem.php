<?php

namespace Exceedone\Exment\ColumnItems;

use Exceedone\Exment\ColumnItems\CustomColumns;
use Encore\Admin\Form\Field;
use Exceedone\Exment\Enums\FormColumnType;

abstract class FormOtherItem implements ItemInterface
{
    use ItemTrait;
    
    protected $form_column;

    public function __construct($form_column){
        $this->form_column = $form_column;
    }

    /**
     * Register custom field.
     *
     * @param string $abstract
     * @param string $class
     *
     * @return void
     */
    public static function extend($abstract, $class)
    {
        static::$availableFields[$abstract] = $class;
    }

    /**
     * get column name
     */
    public function name(){
        return null;
    }

    /**
     * get index name
     */
    public function index(){
        return null;
    }

    /**
     * get Text(for display) 
     */
    public function text(){
        return array_get($this->form_column, 'options.text');
    }

    /**
     * get html(for display) 
     */
    public function html(){
        // default escapes text
        return esc_script_tag($this->text());
    }

    /**
     * sortable for grid
     */
    public function sortable(){
        return false;
    }

    public function setCustomValue($custom_value){
    }

    protected function getTargetValue($custom_value){
        return null;
    }

    public function getAdminField($form_column = null, $column_name_prefix = null){
        $classname = $this->getAdminFieldClass();
        $field = new $classname($this->html());

        return $field;
    }

    abstract protected function getAdminFieldClass();

    protected function setAdminOptions(&$field, $form_column_options){
    }

    public static function getItem(...$args){
        list($form_column) = $args;
        $form_column_name = FormColumnType::getOption(['id' => $form_column->form_column_target_id])['column_name'] ?? null;
                    
        if ($className = static::findItemClass($form_column_name)) {
            return new $className($form_column);
        }
        
        admin_error('Error', "Field type [$form_column_name] does not exist.");

        return null;
    }
    
    /**
     * Find item class.
     *
     * @param string $column_type
     *
     * @return bool|mixed
     */
    public static function findItemClass($column_type)
    {
        $class = array_get(static::$availableFields, $column_type);

        if (class_exists($class)) {
            return $class;
        }

        return false;
    }

    /**
     * Get column validate array.
     * @param string|CustomTable|array $table_obj table object
     * @param string column_name target column name
     * @param array result_options Ex help string, ....
     * @return string
     */
    public function getColumnValidates(&$result_options)
    {
        $custom_table = $this->custom_column->custom_table;
        $custom_column = $this->custom_column;
        $options = array_get($custom_column, 'options');

        $validates = [];
        // setting options --------------------------------------------------
        // unique
        if (boolval(array_get($options, 'unique')) && !boolval(array_get($options, 'multiple_enabled'))) {
            // add unique field
            $unique_table_name = getDBTableName($custom_table); // database table name
            $unique_column_name = "value->".array_get($custom_column, 'column_name'); // column name
            
            $uniqueRules = [$unique_table_name, $unique_column_name];
            // create rules.if isset id, add
            $uniqueRules[] = (isset($value_id) ? "$value_id" : "");
            $uniqueRules[] = 'id';
            // and ignore data deleted_at is NULL 
            $uniqueRules[] = 'deleted_at';
            $uniqueRules[] = 'NULL';
            $rules = "unique:".implode(",", $uniqueRules);
            // add rules
            $validates[] = $rules;
        }

        // // regex rules
        $help_regexes = [];
        if (array_key_value_exists('available_characters', $options)) {
            $available_characters = array_get($options, 'available_characters');
            $regexes = [];
            // add regexes using loop
            foreach ($available_characters as $available_character) {
                switch ($available_character) {
                    case 'lower':
                        $regexes[] = 'a-z';
                        $help_regexes[] = exmtrans('custom_column.available_characters.lower');
                        break;
                    case 'upper':
                        $regexes[] = 'A-Z';
                        $help_regexes[] = exmtrans('custom_column.available_characters.upper');
                        break;
                    case 'number':
                        $regexes[] = '0-9';
                        $help_regexes[] = exmtrans('custom_column.available_characters.number');
                        break;
                    case 'hyphen_underscore':
                        $regexes[] = '_\-';
                        $help_regexes[] = exmtrans('custom_column.available_characters.hyphen_underscore');
                        break;
                    case 'symbol':
                        $regexes[] = '!"#$%&\'()\*\+\-\.,\/:;<=>?@\[\]^_`{}~';
                        $help_regexes[] = exmtrans('custom_column.available_characters.symbol');
                    break;
                }
            }
            if (count($regexes) > 0) {
                $validates[] = 'regex:/^['.implode("", $regexes).']*$/';
            }
        }
        
        // set help_regexes to result_options
        if (count($help_regexes) > 0) {
            $result_options['help_regexes'] = $help_regexes;
        }

        // set column's validates
        $this->setValidates($validates);

        return $validates;
    }
}
