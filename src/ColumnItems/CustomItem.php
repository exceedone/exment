<?php

namespace Exceedone\Exment\ColumnItems;

use Encore\Admin\Form\Field;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Filter;
use Encore\Admin\Form;
use Exceedone\Exment\Form\Field as ExmentField;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumnMulti;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\Traits\ColumnOptionQueryTrait;
use Exceedone\Exment\Enums\FormLabelType;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\FilterType;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\DatabaseDataType;
use Exceedone\Exment\Enums\TextAlignExType;
use Exceedone\Exment\ColumnItems\CustomColumns\AutoNumber;
use Exceedone\Exment\Validator;

abstract class CustomItem implements ItemInterface
{
    use ItemTrait;
    use SystemColumnItemTrait;
    use SummaryItemTrait;
    use ColumnOptionQueryTrait;

    protected $custom_column;

    protected $custom_value;

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


    public function __construct($custom_column, $custom_value, $view_column_target = null)
    {
        $this->custom_column = $custom_column;
        $this->custom_table = CustomTable::getEloquent($custom_column);
        $this->setCustomValue($custom_value);
        $this->options = [];

        $params = static::getOptionParams($view_column_target, $this->custom_table);
        // get label. check not match $this->custom_table and pivot table
        if (array_key_value_exists('view_pivot_table_id', $params) && $this->custom_table->id != $params['view_pivot_table_id']) {
            if ($params['view_pivot_column_id'] == SystemColumn::PARENT_ID) {
                $this->label = static::getViewColumnLabel($this->custom_column->column_view_name, $this->custom_table->table_view_name);
            } else {
                $pivot_column = CustomColumn::getEloquent($params['view_pivot_column_id'], $params['view_pivot_table_id']);
                $this->label = static::getViewColumnLabel($this->custom_column->column_view_name, $pivot_column->column_view_name);
            }
        } else {
            $this->label = $this->custom_column->column_view_name;
        }
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
     * For sql column name.
     * Join table: false
     * Wrap: false
     */
    public function sqlname()
    {
        return $this->custom_column->getQueryKey();
    }

    /**
     * get index name
     */
    public function index()
    {
        return $this->custom_column->getIndexColumnName();
    }

    /**
     * Get API column name
     *
     * @return string
     */
    public function apiName()
    {
        return $this->_apiName();
    }

    /**
     * get Text(for display)
     */
    protected function _text($v)
    {
        return $v;
    }

    /**
     * get html(for display)
     */
    protected function _html($v)
    {
        // default escapes text
        $text = boolval(array_get($this->options, 'grid_column')) ? get_omitted_string($this->_text($v)) : $this->_text($v);
        return esc_html($text);
    }

    /**
     * get grid style
     */
    public function gridStyle()
    {
        $array = [
            'min-width' => $this->custom_column->getOption('min_width', config('exment.grid_min_width', 100)) . 'px',
            'max-width' => $this->custom_column->getOption('max_width', config('exment.grid_max_width', 300)) . 'px',
        ];
        $text_align = $this->custom_column->getOption('text_align');
        if (isset($text_align)) {
            $array['text-align'] = $text_align;
        }
        return $this->getStyleString($array);
    }

    /**
     * get grid header style
     */
    public function gridHeaderStyle()
    {
        $array = [];

        $header_align = array_get($this->options, 'header_align')?? TextAlignExType::LEFT;
        if ($header_align == TextAlignExType::INHERIT) {
            $text_align = $this->custom_column->getOption('text_align');
            if (isset($text_align)) {
                $array['text-align'] = $text_align;
            }
        }
        return $array;
    }

    /**
     * sortable for grid
     */
    public function sortable()
    {
        return $this->indexEnabled() && !$this->isMultipleEnabled();
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
        $this->custom_value = $this->getTargetCustomValue($custom_value);
        $this->value = $this->getTargetValue($custom_value);
        if (isset($custom_value)) {
            $this->id = array_get($custom_value, 'id');
        }

        $this->prepare();

        return $this;
    }

    public function getCustomColumn()
    {
        return $this->custom_column;
    }

    /**
     * Get relation.
     *
     * @return CustomRelation|null
     */
    public function getRelation()
    {
        return $this->getRelationTrait();
    }


    protected function getTargetValue($custom_value)
    {
        // if options has "summary" (for summary view)
        if (boolval(array_get($this->options, 'summary'))) {
            $v = array_get($custom_value, $this->sqlAsName());
            if (array_get($this->options, 'group_condition') == 'w') {
                return $this->getWeekdayFormat($v);
            }
            return $v;
        }
        // if options has "summary_child" (for not only summary view, but also default view)
        if (isset($custom_value) && boolval(array_get($this->options, 'summary_child'))) {
            return $custom_value->getSum($this->custom_column);
        }

        // if options has "view_pivot_column", get select_table's custom_value first
        if (isset($custom_value) && array_key_value_exists('view_pivot_column', $this->options)) {
            return $this->getViewPivotValue($custom_value, $this->options);
        }

        return array_get($custom_value, 'value.'.$this->custom_column->column_name);
    }

    /**
     * Get help string.
     *
     * @return string|null
     */
    public function getHelp(): ?string
    {
        // if initonly is true and has value, not showing help
        if ($this->initonly()) {
            return null;
        }

        // set help string using result_options ----------------------------------------------------
        $help = null;
        if (array_key_value_exists('help', $this->form_column_options)) {
            $help = array_get($this->form_column_options, 'help');
        } elseif (array_key_value_exists('help', $this->custom_column->options)) {
            $help = array_get($this->custom_column->options, 'help');
        }

        // if initonly is true and now, showing help and cannot edit help
        elseif (!boolval(array_get($this->options, 'public_form')) && boolval(array_get($this->custom_column->options, 'init_only'))) {
            $help .= exmtrans('common.help.init_flg');
        }

        return $help;
    }


    /**
     * Get default value.
     *
     * @return mixed
     */
    public function getDefaultValue()
    {
        // If change field, return null
        if (boolval(array_get($this->options, 'changefield', false))) {
            return null;
        }

        // get request query
        $default = $this->getDefaultValueByQuery();
        if (!is_nullorempty($default)) {
            return $default;
        }

        // If initonly, not set default
        if ($this->initonly()) {
            return null;
        }

        // get each default value definition
        $default = $this->_getDefaultValue();
        if (!is_nullorempty($default)) {
            return $default;
        }

        // default
        list($default_type, $default) = $this->getDefaultSetting();
        $options = $this->custom_column->options;
        if (!is_nullorempty($default)) {
            return $default;
        }
        return null;
    }


    /**
     * Get default type and value
     *
     * @return array offset 0: type, 1: value
     */
    protected function getDefaultSetting()
    {
        $default_type = array_get($this->form_column_options, 'default_type');
        $default = array_get($this->form_column_options, 'default');
        if (is_nullorempty($default_type) && is_nullorempty($default)) {
            $default_type = array_get($this->custom_column->options, 'default_type');
            $default = array_get($this->custom_column->options, 'default');
        }

        return [$default_type, $default];
    }

    /**
     * Get default value by query string
     *
     * @return mixed
     */
    protected function getDefaultValueByQuery()
    {
        if (!is_nullorempty($this->id)) {
            return null;
        }

        if ($this->isDefferentFormTable()) {
            return null;
        }

        if (!boolval(array_get($this->options, 'enable_default_query'))) {
            return null;
        }

        // get request query
        $default = request()->query("value_" . $this->name());
        if (is_nullorempty($default)) {
            return null;
        }
        return $this->getPureValueByQuery($default);
    }

    /**
     * Get pure value by query string
     *
     * @param string $default
     * @return mixed
     */
    protected function getPureValueByQuery($default)
    {
        return $this->getPureValue($default) ?? $default;
    }


    /**
     * Get default value(define each custom column)
     *
     * @return mixed
     */
    protected function _getDefaultValue()
    {
        return null;
    }


    public function getFilterField($value_type = null)
    {
        if (get_class($this) == AutoNumber::class) {
            $field = $this->getCustomField(Field\Text::class);
            return $field->default('');
        } else {
            switch ($value_type) {
                case FilterType::DAY:
                    $classname = Field\Date::class;
                    break;
                case FilterType::NUMBER:
                    $classname = Field\Number::class;
                    break;
                case FilterType::SELECT:
                    $classname = Field\MultipleSelect::class;
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


    /**
     * Whether is show filter null check
     *
     * @return bool
     */
    public function isShowFilterNullCheck(): bool
    {
        return true;
    }

    protected function getFilterFieldClass()
    {
        return $this->getAdminFieldClass();
    }

    public function getAdminField($form_column = null, $column_name_prefix = null)
    {
        $form_column_options = $form_column->options ?? [];
        $this->form_column_options = array_merge($this->form_column_options, $form_column_options);

        // if hidden setting, add hidden field
        if ($this->hidden()) {
            $classname = Field\Hidden::class;
        } elseif ($this->initonly()) {
            $classname = ExmentField\InitOnly::class;
        } elseif ($this->viewonly()) {
            $classname = ExmentField\ViewOnly::class;
        } elseif ($this->internal()) {
            $classname = ExmentField\Internal::class;
        } else {
            // get field
            $classname = $this->getAdminFieldClass();
        }

        return $this->getCustomField($classname, $column_name_prefix);
    }

    protected function getCustomField($classname, $column_name_prefix = null)
    {
        $options = $this->custom_column->options;
        // form column name. join $column_name_prefix and $column_name
        $form_column_name = $column_name_prefix.$this->name();

        $field = new $classname($form_column_name, [array_get($this->form_column_options, 'form_column_view_name') ?? $this->label()]);
        if ($this->isSetAdminOptions()) {
            $this->setAdminOptions($field);
        }

        if (!$this->hidden()) {
            if ($this->initonly()) {
                $field->displayText($this->html())->escape(false)->default($this->value)->prepareDefault();
            } elseif ($this->viewonly() && !isset($this->value)) {
                // if view only and create, set default value
                $this->value = $this->getDefaultValue();
                $field->displayText($this->html())->escape(false);
                $this->value = null;
            } elseif ($this->viewonly()) {
                $field->displayText($this->html())->escape(false);
            }
        }

        ///////// get common options
        if (array_key_value_exists('placeholder', $options)) {
            $field->placeholder(array_get($options, 'placeholder'));
        }

        // default
        if (is_null($this->id) && !is_null($default = $this->getDefaultValue())) {
            $field->default($default);
        }

        // readonly
        if ($this->readonly()) {
            $field->readonly();
        }

        // suggest input
        if (boolval(array_get($options, 'suggest_input'))) {
            $url = admin_urls('webapi/data', $this->custom_table->table_name, 'column', $this->name());
            $field->attribute(['suggest_url' => $url]);
        }

        // set label
        $this->setLabelType($field);

        // set validates
        $field->rules($this->getColumnValidates($field));

        // get help
        $help = $this->getHelp();
        if (isset($help)) {
            $field->help(html_clean($help));
        }
        // append help
        $this->appendHelp($field);

        $field->attribute(['data-column_type' => $this->custom_column->column_type]);

        $field->setElementClass("class_" . $this->uniqueName());

        return $field;
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
                return FilterType::NUMBER;
            case ColumnType::SELECT:
            case ColumnType::SELECT_VALTEXT:
            case ColumnType::SELECT_TABLE:
            case SystemTableName::ORGANIZATION:
                return FilterType::SELECT;
            case ColumnType::YESNO:
            case ColumnType::BOOLEAN:
                return FilterType::YESNO;
            case ColumnType::DATE:
            case ColumnType::DATETIME:
                return FilterType::DAY;
            case ColumnType::IMAGE:
            case ColumnType::FILE:
                return FilterType::FILE;
            case SystemTableName::USER:
                return FilterType::USER;
            default:
                return FilterType::DEFAULT;
        }
    }

    /**
     * get sort name
     */
    public function getSortName()
    {
        return $this->sqlUniqueTableName() .'.'. $this->custom_column->getQueryKey();
    }

    /**
     * get cast name for sort
     */
    public function getCastName()
    {
        list($type, $addOption, $options) = $this->getCastOptions();
        // if DatabaseDataType::TYPE_STRING, return null
        if (isMatchString($type, DatabaseDataType::TYPE_STRING)) {
            return null;
        }

        $grammar = \DB::getQueryGrammar();
        return $grammar->getCastString($type, $addOption, $options);
    }

    /**
     * get cast name for virtual column database
     */
    public function getVirtualColumnTypeName()
    {
        list($type, $addOption, $options) = $this->getCastOptions();
        $grammar = \DB::getQueryGrammar();
        return $grammar->getColumnTypeString($type);
    }

    /**
     * Set label type to field
     *
     * @param Field $field
     * @return void
     */
    protected function setLabelType(&$field)
    {
        $field_label_type = $this->getLabelType();
        switch ($field_label_type) {
            case FormLabelType::HORIZONTAL:
                return;
            case FormLabelType::VERTICAL:
                $field->disableHorizontal();
                return;
            case FormLabelType::HIDDEN:
                $field->disableHorizontal();
                $field->disableLabel();
                return;
        }
    }

    /**
     * get original field value
     *
     * @return mixed
     */
    protected function getOriginalValue()
    {
        return array_get($this->custom_value->getOriginal(), 'value.' . $this->custom_column->column_name);
    }

    protected function getCastOptions()
    {
        return [DatabaseDataType::TYPE_STRING, false, []];
    }

    /**
     * get value before saving
     */
    public function saving()
    {
    }

    /**
     * get value after saving
     */
    public function saved()
    {
    }

    protected function disableEdit()
    {
        if ($this->initonly()) {
            return true;
        }

        if ($this->readonly()) {
            return true;
        }

        return false;
    }

    /**
     * replace value for import
     *
     * @param mixed $value
     * @param array $options
     * @return array
     *     result : import result is true or false.
     *     message : If error, showing error message
     *     skip :Iif true, skip import this column.
     *     value : Replaced value.
     */
    public function getImportValue($value, $options = [])
    {
        return [
            'result' => true,
            'value' => $value,
        ];
    }

    abstract protected function getAdminFieldClass();

    protected function setAdminOptions(&$field)
    {
    }

    protected function setAdminFilterOptions(&$filter)
    {
    }

    protected function setValidates(&$validates)
    {
    }

    protected function getAppendHelpText(): ?string
    {
        return null;
    }

    protected function appendHelp(Field $field)
    {
        $text = $this->getAppendHelpText();
        if (is_nullorempty($text)) {
            return;
        }

        $field->appendHelp($text);
    }

    public static function getItem(...$args)
    {
        list($custom_column, $custom_value, $view_column_target) = $args + [null, null, null];
        $column_type = $custom_column->column_type;

        if ($className = static::findItemClass($column_type)) {
            return new $className($custom_column, $custom_value, $view_column_target);
        }

        admin_error('Error', "Field type [$column_type] does not exist.");

        return null;
    }

    /**
     * Get font awesome class
     *
     * @return string|null
     */
    public function getFontAwesomeClass(): ?string
    {
        return $this->custom_column->getFontAwesomeClass();
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
     * @param Field $field
     * @return array
     */
    public function getColumnValidates(Field $field)
    {
        $options = array_get($this->custom_column, 'options');
        $validates = [];

        // setting options --------------------------------------------------
        // required
        if ($this->required()) {
            $field->required();
            $validates[] = 'required';
        } else {
            $validates[] = 'nullable';
        }

        ///// unique rule moves to validatorSaving logic

        // init_flg(for validation)
        if ($this->initonly()) {
            $validates[] = new Validator\InitOnlyRule($this->custom_column, $this->custom_value);
        } else {
            // set column's validates
            $this->setValidates($validates);
        }


        // get removing fields.
        $field->removeRules($this->getRemoveValidates());

        return $validates;
    }

    /**
     * Get remove validate array.
     * @return array if want to remove, append removing array.
     */
    protected function getRemoveValidates()
    {
        return [];
    }


    /**
     * Compare two values.
     */
    public function compareTwoValues(CustomColumnMulti $compare_column, $this_value, $target_value)
    {
        return true;
    }


    public function initonly()
    {
        $initOnly = boolval(array_get($this->custom_column->options, 'init_only'));

        return $initOnly && isset($this->value);
    }

    public function readonly()
    {
        return array_boolval($this->form_column_options, 'read_only') || array_get($this->form_column_options, 'field_showing_type') == 'read_only';
    }

    public function viewonly()
    {
        return array_boolval($this->form_column_options, 'view_only') || array_get($this->form_column_options, 'field_showing_type') == 'view_only';
    }

    public function hidden()
    {
        return array_boolval($this->form_column_options, 'hidden') || array_get($this->form_column_options, 'field_showing_type') == 'hidden';
    }

    public function internal()
    {
        return array_boolval($this->form_column_options, 'internal') || array_get($this->form_column_options, 'field_showing_type') == 'internal';
    }

    /**
     * Hide when showing display
     *
     * @return bool
     */
    public function disableDisplayWhenShow(): bool
    {
        if ($this->internal() || $this->hidden()) {
            return true;
        }

        // If config EXMENT_DISABLE_SHOW_READONLY AND EXMENT_DISABLE_SHOW_VIEWONLY
        if (boolval(config('exment.disable_show_field_readonly', false)) && $this->readonly()) {
            return true;
        }

        if (boolval(config('exment.disable_show_field_viewonly', false)) && $this->viewonly()) {
            return true;
        }

        return false;
    }

    public function required()
    {
        if ($this->initonly() || $this->viewonly() || $this->internal()) {
            return false;
        }
        if (boolval(array_get($this->options, 'is_operation'))) {
            return false;
        }
        if (!$this->required) {
            return false;
        }

        $options = array_get($this->custom_column, 'options');
        return boolval(array_get($options, 'required')) || boolval(array_get($this->form_column_options, 'required'));
    }

    protected function isSetAdminOptions(): bool
    {
        return !$this->hidden() &&
            !$this->initonly() &&
            !$this->viewonly() &&
            !$this->internal();
    }


    /**
     * Set Custom Column Form(defalut and form). Using laravel-admin form option
     * https://laravel-admin.org/docs/#/en/model-form-fields
     *
     * @param Form $form
     * @return void
     */
    public function setCustomColumnForm(&$form)
    {
        $this->setCustomColumnDefaultValueForm($form);
        $this->setCustomColumnOptionForm($form);
    }

    /**
     * Set Custom Column Option Form. Using laravel-admin form option
     * https://laravel-admin.org/docs/#/en/model-form-fields
     *
     * @param Form $form
     * @return void
     */
    public function setCustomColumnOptionForm(&$form)
    {
    }

    /**
     * Set Custom Column Option Form. Using laravel-admin form option
     * https://laravel-admin.org/docs/#/en/model-form-fields
     *
     * @param Form $form
     * @return void
     */
    public function setCustomColumnDefaultValueForm(&$form, bool $asCustomForm = false)
    {
        $form->text('default', exmtrans("custom_column.options.default"))
            ->help(exmtrans("custom_column.help.default"));
    }
}
