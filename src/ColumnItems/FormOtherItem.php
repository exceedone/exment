<?php

namespace Exceedone\Exment\ColumnItems;

use Encore\Admin\Form\Field;
use Exceedone\Exment\Enums\FilterType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Enums\FormLabelType;
use Encore\Admin\Show\Field as ShowField;

abstract class FormOtherItem implements ItemInterface
{
    use ItemTrait;

    protected $form_column;

    protected $custom_value;

    /**
     * Available fields.
     *
     * @var array
     */
    public static $availableFields = [];

    public function __construct($form_column)
    {
        $this->form_column = $form_column;
        $this->label = ' ';
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
        return make_uuid();
    }

    /**
     * sqlname
     */
    public function sqlname()
    {
        return null;
    }

    /**
     * get index name
     */
    public function index()
    {
        return null;
    }

    /**
     * get Text(for display)
     */
    protected function _text($v)
    {
        return array_get($this->form_column_options, 'text');
    }

    /**
     * get html(for display)
     * *Please escape
     */
    protected function _html($v)
    {
        // default escapes text
        return html_clean($this->_text($v));
    }

    /**
     * get grid style
     */
    public function gridStyle()
    {
        return $this->getStyleString();
    }

    /**
     * sortable for grid
     */
    public function sortable()
    {
        return false;
    }

    public function setCustomValue($custom_value)
    {
        $this->custom_value = $custom_value;
        return $this;
    }

    public function getCustomTable()
    {
        return $this->custom_column->custom_table;
    }

    protected function getTargetValue($custom_value)
    {
        return null;
    }

    public function getAdminField($form_column = null, $column_name_prefix = null)
    {
        if (is_array($form_column)) {
            $form_column_options = $form_column;
        } else {
            $form_column_options = $form_column->options ?? null;
        }
        if (!is_nullorempty($form_column_options)) {
            $this->form_column_options = $form_column_options;
        }

        $classname = $this->getAdminFieldClass();
        $field = new $classname($this->html(), []);
        $this->setAdminOptions($field);

        return $field;
    }

    abstract protected function getAdminFieldClass();

    protected function setAdminOptions(&$field)
    {
        $field_label_type = $this->getLabelType();
        // get form info
        switch ($field_label_type) {
            case FormLabelType::HORIZONTAL:
                break;
            case FormLabelType::VERTICAL:
                $field->disableHorizontal();
                break;
            case FormLabelType::HIDDEN:
                $field->disableHorizontal();
                $field->disableLabel();
                break;
        }
    }

    /**
     * Set show field options
     *
     * @param ShowField $field
     * @param array $options
     * @return void
     */
    public function setShowFieldOptions(ShowField $field, array $options = [])
    {
        $item = $this;

        $field->as(function ($v) use ($item) {
            /** @phpstan-ignore-next-line Call to function is_null() with $this(Exceedone\Exment\ColumnItems\FormOtherItem) will always evaluate to false. */
            if (is_null($this)) {
                return '';
            }
            return $item->setCustomValue($this)->html();
        })->setEscape(false);

        // If grid shows, set label style
        if ($options['gridShows']) {
            $this->setAdminOptions($field);
        }

        $field->setWidth(12, 0);
    }

    public static function getItem(...$args)
    {
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
        if (!$column_type) {
            return false;
        }

        $class = array_get(static::$availableFields, $column_type);

        if (class_exists($class)) {
            return $class;
        }

        return false;
    }

    /**
     * get view filter type
     */
    public function getViewFilterType()
    {
        return FilterType::DEFAULT;
    }

    /**
     * get sqlname for summary
     * *Please override if use.
     * Join table: true
     * Wrap: true
     *
     * @return string Ex: COUNT(`exm__3914ac5180d7dc43fcbb AS AAAA`)
     */
    public function getSummaryWrapTableColumn(): string
    {
        return '';
    }
}
