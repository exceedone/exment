<?php

namespace Exceedone\Exment\ColumnItems;

use Encore\Admin\Form\Field;
use Encore\Admin\Grid\Filter;
use Exceedone\Exment\Form\Field as ExmentField;
use Exceedone\Exment\Grid\Filter as ExmentFilter;
use Encore\Admin\Grid\Filter\Where;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\ViewColumnFilterType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\ColumnItems\CustomColumns\AutoNumber;

abstract class CustomItem implements ItemInterface
{
    use ItemTrait;
    use SummaryItemTrait;
    
    protected $custom_column;
    
    protected $custom_table;

    /**
     * laravel-admin set required. if false, always not-set required
     */
    protected $required = true;

    /**
     * Available fields.
     *
     * @var array
     */
    public static $availableFields = [];


    public function __construct($custom_column, $custom_value)
    {
        $this->custom_column = $custom_column;
        $this->custom_table = $custom_column->custom_table;
        $this->label = $this->custom_column->column_view_name;
        $this->setCustomValue($custom_value);
        $this->options = [];
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
    public function name()
    {
        return $this->custom_column->column_name;
    }

    /**
     * sqlname
     */
    public function sqlname()
    {
        if (boolval(array_get($this->options, 'summary'))) {
            return $this->getSummarySqlName();
        }
        if (boolval(array_get($this->options, 'groupby'))) {
            return $this->getGroupBySqlName();
        }
        if (!$this->custom_column->index_enabled) {
            return 'value->'.$this->custom_column->column_name;
        }
        return $this->index();
    }

    /**
     * get index name
     */
    public function index()
    {
        return $this->custom_column->getIndexColumnName();
    }

    /**
     * get Text(for display)
     */
    public function text()
    {
        return $this->value;
    }

    /**
     * get html(for display)
     */
    public function html()
    {
        // default escapes text
        return esc_html($this->text());
    }

    /**
     * sortable for grid
     */
    public function sortable()
    {
        return $this->indexEnabled();
    }

    /**
     * whether column is enabled index.
     *
     */
    public function indexEnabled()
    {
        return $this->custom_column->index_enabled;
    }

    public function setCustomValue($custom_value)
    {
        $this->value = $this->getTargetValue($custom_value);
        if (isset($custom_value)) {
            $this->id = array_get($custom_value, 'id');
        }

        $this->prepare();
        
        return $this;
    }

    public function getCustomTable()
    {
        return $this->custom_table;
    }

    protected function getTargetValue($custom_value)
    {
        // if options has "summary" (for summary view)
        if (boolval(array_get($this->options, 'summary'))) {
            return array_get($custom_value, $this->sqlAsName());
        }
        // if options has "summary_child" (for not only summary view, but also default view)
        if (isset($custom_value) && boolval(array_get($this->options, 'summary_child'))) {
            return $custom_value->getSum($this->custom_column);
        }
        return array_get($custom_value, 'value.'.$this->custom_column->column_name);
    }
    
    public function getFilterField($value_type = null)
    {
        if (get_class($this) == AutoNumber::class) {
            $field = $this->getCustomField(Field\Text::class);
            return $field->default('');
        } else {
            switch ($value_type) {
                case ViewColumnFilterType::DAY:
                    $classname = Field\Date::class;
                    break;
                case ViewColumnFilterType::NUMBER:
                    $classname = Field\Number::class;
                    break;
                case ViewColumnFilterType::SELECT:
                    $classname = Field\Select::class;
                    break;
                default:
                    $classname = $this->getFilterFieldClass();
                    break;
            }
        }

        // set disable_number_format
        $this->custom_column->setOption('number_format', false);
        $this->options['disable_number_format'] = true;

        return $this->getCustomField($classname);
    }
    protected function getFilterFieldClass()
    {
        return $this->getAdminFieldClass();
    }

    public function getAdminField($form_column = null, $column_name_prefix = null)
    {
        $form_column_options = $form_column->options ?? null;

        // if hidden setting, add hidden field
        if (boolval(array_get($form_column_options, 'hidden'))) {
            $classname = Field\Hidden::class;
        }elseif ($this->initonly() && isset($this->id)) {
            $classname = ExmentField\Display::class;
        } else {
            // get field
            $classname = $this->getAdminFieldClass();
        }

        return $this->getCustomField($classname, $form_column_options, $column_name_prefix);
    }

    protected function getCustomField($classname, $form_column_options = null, $column_name_prefix = null)
    {
        $options = $this->custom_column->options;
        // form column name. join $column_name_prefix and $column_name
        $form_column_name = $column_name_prefix.$this->name();
        
        $field = new $classname($form_column_name, [$this->label()]);
        if($this->isSetAdminOptions($form_column_options)){
            $this->setAdminOptions($field, $form_column_options);
        }

        if($this->initonly() && isset($this->id)){
            $field->displayText($this->html());
        }

        ///////// get common options
        if (array_key_value_exists('placeholder', $options)) {
            $field->placeholder(array_get($options, 'placeholder'));
        }

        // default
        if (array_key_value_exists('default', $options)) {
            $field->default(array_get($options, 'default'));
        }

        // number_format
        if (boolval(array_get($options, 'number_format'))) {
            $field->attribute(['number_format' => true]);
        }

        // // readonly
        if (boolval(array_get($form_column_options, 'view_only'))) {
            $field->readonly();
        }

        // required
        if (boolval(array_get($options, 'required')) && $this->required) {
            $field->required();
            $field->rules('required');
        } else {
            $field->rules('nullable');
        }

        // suggest input
        if (boolval(array_get($options, 'suggest_input'))) {
            $url = admin_urls('webapi/data', $this->custom_table->table_name, 'column', $this->name());
            $field->attribute(['suggest_url' => $url]);
        }

        // set validates
        $validate_options = [];
        $validates = $this->getColumnValidates($validate_options);
        // set validates
        if (count($validates)) {
            $field->rules($validates);
        }

        // set help string using result_options
        $help = null;
        if (array_key_value_exists('help', $options)) {
            $help = array_get($options, 'help');
        }
        $help_regexes = array_get($validate_options, 'help_regexes');
        
        // if initonly is true and edit, not showing help
        if ($this->initonly() && isset($this->id)) {
            $help = null;
        }
        // if initonly is true and now, showing help and cannot edit help
        elseif($this->initonly() && !isset($this->id)){
            $help .= exmtrans('custom_value.help.init_flg');
            if (isset($help_regexes)) {
                $help .= sprintf(exmtrans('common.help.input_available_characters'), implode(exmtrans('common.separate_word'), $help_regexes));
            }
        }
        // if initonly is false, showing help
        else{
            if (isset($help_regexes)) {
                $help .= sprintf(exmtrans('common.help.input_available_characters'), implode(exmtrans('common.separate_word'), $help_regexes));
            }
        }

        if (isset($help)) {
            $field->help(esc_html($help));
        }

        $field->attribute(['data-column_type' => $this->custom_column->column_type]);

        return $field;
    }

    /**
     * set admin filter
     */
    public function setAdminFilter(&$filter)
    {
        $classname = $this->getAdminFilterClass();

        // if where query, call Cloquire
        if ($classname == Where::class) {
            $item = $this;
            $filteritem = new $classname(function ($query) use ($item) {
                $item->getAdminFilterWhereQuery($query, $this->input);
            }, $this->label());
        } else {
            $filteritem = new $classname($this->index(), $this->label());
        }

        // first, set $filter->use
        $filter->use($filteritem);

        // next, set admin filter options
        $this->setAdminFilterOptions($filteritem);
    }

    /**
     * get view filter type
     */
    public function getViewFilterType()
    {
        // get column_type
        $database_column_type = $this->custom_column->column_type;
        switch ($database_column_type) {
            case ColumnType::INTEGER:
            case ColumnType::DECIMAL:
            case ColumnType::CURRENCY:
                return ViewColumnFilterType::NUMBER;
            case ColumnType::SELECT:
            case ColumnType::SELECT_VALTEXT:
            case ColumnType::SELECT_TABLE:
                return ViewColumnFilterType::SELECT;
            case ColumnType::DATE:
            case ColumnType::DATETIME:
                return ViewColumnFilterType::DAY;
            case ColumnType::IMAGE:
            case ColumnType::FILE:
                return ViewColumnFilterType::FILE;
            case SystemTableName::USER:
                return ViewColumnFilterType::USER;
            default:
                return ViewColumnFilterType::DEFAULT;
        }
    }

    /**
     * get value before saving
     *
     * @return void
     */
    public function saving()
    {
    }

    /**
     * get value after saving
     *
     * @return void
     */
    public function saved()
    {
    }

    /**
     * replace value for import
     *
     * @param mixed $value
     * @param array $options
     * @return void
     */
    public function getImportValue($value, $options = [])
    {
        return $value;
    }

    abstract protected function getAdminFieldClass();

    protected function getAdminFilterClass()
    {
        if (boolval(config('exment.filter_search_full', false))) {
            return Filter\Like::class;
        }

        return ExmentFilter\StartsWith::class;
    }

    protected function setAdminOptions(&$field, $form_column_options)
    {
    }

    protected function setAdminFilterOptions(&$filter)
    {
    }
    
    protected function setValidates(&$validates)
    {
    }

    public static function getItem(...$args)
    {
        list($custom_column, $custom_value) = $args + [null, null];
        $column_type = $custom_column->column_type;

        if ($className = static::findItemClass($column_type)) {
            return new $className($custom_column, $custom_value);
        }
        
        admin_error('Error', "Field type [$column_type] does not exist.");

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
        $options = array_get($this->custom_column, 'options');

        $validates = [];
        // setting options --------------------------------------------------
        // unique
        if (boolval(array_get($options, 'unique')) && !boolval(array_get($options, 'multiple_enabled'))) {
            // add unique field
            $unique_table_name = getDBTableName($this->custom_table); // database table name
            $unique_column_name = "value->".array_get($this->custom_column, 'column_name'); // column name
            
            $uniqueRules = [$unique_table_name, $unique_column_name];
            // create rules.if isset id, add
            $uniqueRules[] = $this->id ?? '';
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
        if (boolval(config('exment.expart_mode', false)) && array_key_value_exists('regex_validate', $options)) {
            $regex_validate = array_get($options, 'regex_validate');
            $validates[] = 'regex:/'.$regex_validate.'/u';
        } elseif (array_key_value_exists('available_characters', $options)) {
            $available_characters = array_get($options, 'available_characters') ?? [];
            if (is_string($available_characters)) {
                $available_characters = explode(",", $available_characters);
            }
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
                $validates[] = 'regex:/^['.implode("", $regexes).']*$/u';
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

    protected function initonly(){
        $initOnly = boolval(array_get($this->custom_column->options, 'init_only'));
        if($initOnly){
            $this->required = false;
        }
        return $initOnly;
    }

    protected function isSetAdminOptions($form_column_options){
        if (boolval(array_get($form_column_options, 'hidden'))) {
            return false;
        }elseif ($this->initonly() && isset($this->id)) {
            return false;
        }

        return true;
    }
}
