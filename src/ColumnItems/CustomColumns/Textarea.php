<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Encore\Admin\Form;
use Encore\Admin\Form\Field;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Validator;

class Textarea extends CustomItem
{
    public function saving()
    {
        if (is_nullorempty($this->value)) {
            return;
        }
        return strval($this->value);
    }

    protected function _html($v)
    {
        $text = $this->_text($v);
        $text = boolval(array_get($this->options, 'grid_column')) ? get_omitted_string($text) : $text;
        $text = replaceBreakEsc($text);

        if (!config('exment.textarea_space_tag', true)) {
            return $text;
        }

        // replace space to tag
        return preg_replace('/ /', '<span style="margin-right: 0.5em;"></span>', $text);
    }
    protected function getAdminFieldClass()
    {
        return Field\Textarea::class;
    }
    
    protected function setAdminOptions(&$field)
    {
        $options = $this->custom_column->options;
        $field->rows(array_get($options, 'rows', 6));

        if (array_get($options, 'string_length')) {
            $field->attribute(['maxlength' => array_get($options, 'string_length')]);
        }
    }
    
    protected function setValidates(&$validates)
    {
        $options = $this->custom_column->options;
        
        // value size
        if (array_get($options, 'string_length')) {
            $validates[] = 'max:'.array_get($options, 'string_length');
        }

        // value string
        $validates[] = new Validator\StringNumericRule();
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
        // text
        // string length
        $form->number('string_length', exmtrans("custom_column.options.string_length"))
            ->default(256);

        $form->number('rows', exmtrans("custom_column.options.rows"))
            ->default(6)
            ->min(1)
            ->max(30)
            ->help(exmtrans("custom_column.help.rows"));
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
        $form->textarea('default', exmtrans("custom_column.options.default"))
            ->help(exmtrans("custom_column.help.default"))
            ->rows(3);
    }

    
    /**
     * Get grid filter option. Use grid filter, Ex. LIKE search.
     *
     * @return string
     */
    protected function getGridFilterOption() : ?string
    {
        return FilterOption::LIKE;
    }
}
