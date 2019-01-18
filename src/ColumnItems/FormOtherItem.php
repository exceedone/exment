<?php

namespace Exceedone\Exment\ColumnItems;

use Exceedone\Exment\ColumnItems\CustomColumns;
use Encore\Admin\Form\Field;
use Exceedone\Exment\Enums\FormColumnType;

abstract class FormOtherItem implements ItemInterface
{
    use ItemTrait;
    
    protected $form_column;

    /**
     * Available fields.
     *
     * @var array
     */
    public static $availableFields = [];

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
     * sqlname
     */
    public function sqlname(){
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
        return $this;
    }

    public function getCustomTable(){
        return $this->custom_column->custom_table;
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
        list($form_column) = $args + [null];
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
}
