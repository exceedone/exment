<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\Enums\FilterOption;
use Encore\Admin\Form;
use Encore\Admin\Form\Field;

class AutoNumber extends CustomItem
{
    protected $required = false;

    protected function getAdminFieldClass()
    {
        return Field\Display::class;
    }

    protected function setAdminOptions(&$field)
    {
        if (!isset($this->id)) {
            $field->default(exmtrans('custom_value.auto_number_create'));
        }
    }

    /**
     * Get grid filter option. Use grid filter, Ex. LIKE search.
     *
     * @return string|null
     */
    protected function getGridFilterOption(): ?string
    {
        return (string)FilterOption::LIKE;
    }

    /**
     * get auto number value
     */
    public function saved()
    {
        // already set value, break
        if (isset($this->value)) {
            return null;
        }

        $options = $this->custom_column->options;
        if (!isset($options)) {
            return null;
        }

        if (array_get($options, 'auto_number_type') == 'format') {
            return $this->createAutoNumberFormat($options);
        }
        // if auto_number_type is random25, set value
        elseif (array_get($options, 'auto_number_type') == 'random25') {
            return make_licensecode();
        }
        // if auto_number_type is UUID, set value
        elseif (array_get($options, 'auto_number_type') == 'random32') {
            return make_uuid();
        }

        return null;
    }

    /**
     * Create Auto Number value using format.
     */
    protected function createAutoNumberFormat($options)
    {
        // get format
        $format = array_get($options, "auto_number_format");
        // get value
        $value = getModelName($this->custom_column->custom_table)::withoutGlobalScopes()->find($this->id);
        $auto_number = replaceTextFromFormat($format, $value);
        return $auto_number;
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
        // auto numbering
        $form->select('auto_number_type', exmtrans("custom_column.options.auto_number_type"))
            ->required()
            ->options(
                [
                'format' => exmtrans("custom_column.options.auto_number_type_format"),
                'random25' => exmtrans("custom_column.options.auto_number_type_random25"),
                'random32' => exmtrans("custom_column.options.auto_number_type_random32"),
                'other' => exmtrans("custom_column.options.auto_number_other"),
                ]
            )
            ->attribute(['data-filtertrigger' =>true]);

        // set manual
        $manual_url = getManualUrl('column?id='.exmtrans('custom_column.auto_number_format_rule'));
        $form->text('auto_number_format', exmtrans("custom_column.options.auto_number_format"))
            ->attribute(['data-filter' => json_encode([
                ['parent' => 1, 'key' => 'options_auto_number_type', 'value' => 'format'],
            ])])
            ->help(sprintf(exmtrans("custom_column.help.auto_number_format"), $manual_url))
        ;
    }

    /**
     * Set Custom Column Option default value Form. Using laravel-admin form option
     * https://laravel-admin.org/docs/#/en/model-form-fields
     *
     * @param Form $form
     * @return void
     */
    public function setCustomColumnDefaultValueForm(&$form, bool $asCustomForm = false)
    {
    }


    /**
     * Get default value.
     *
     * @return mixed
     */
    public function getDefaultValue()
    {
        return null;
    }
}
