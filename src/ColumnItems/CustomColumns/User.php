<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Encore\Admin\Form;
use Exceedone\Exment\Enums\ColumnDefaultType;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;

class User extends SelectTable
{
    public function __construct($custom_column, $custom_value, $view_column_target = null)
    {
        parent::__construct($custom_column, $custom_value, $view_column_target);

        $this->target_table = CustomTable::getEloquent(SystemTableName::USER);
    }


    /**
     * Get default value.
     *
     * @return mixed
     */
    protected function _getDefaultValue()
    {
        if (boolval(array_get($this->options, 'changefield', false))) {
            return null;
        }

        $options = $this->custom_column->options;
        list($default_type, $default) = $this->getDefaultSetting();

        // default (login user)
        if (isMatchString($default_type, ColumnDefaultType::LOGIN_USER)) {
            return \Exment::getUserId();
        }

        // default (login user)
        if (!$this->initonly() && boolval(array_get($options, 'login_user_default'))) {
            return \Exment::getUserId();
        }

        return null;
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
        $this->setCustomColumnOptionFormSelectTable($form, ColumnType::USER);

        $form->switchbool('showing_all_user_organizations', exmtrans("custom_column.options.showing_all_user_organizations"))
            ->help(exmtrans("custom_column.help.showing_all_user_organizations"))
            ->default('0');
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
        $form->select('default_type', exmtrans("custom_column.options.default_type"))
            ->attribute(['data-filtertrigger' =>true])
            ->help(exmtrans("custom_column.help.default_type"))
            ->options(getTransArray(ColumnDefaultType::COLUMN_DEFAULT_TYPE_USER(), 'custom_column.column_default_type_options'));

        $form->text('default', exmtrans("custom_column.options.default"))
            ->help(exmtrans("custom_column.help.default"))
            ->attribute(['data-filter' => json_encode(['parent' => !$asCustomForm, 'key' => $asCustomForm ? 'default_type' : 'options_default_type', 'value' => ColumnDefaultType::SELECT_USER])])
        ;
    }

    /**
     * Get default type and value
     *
     * @return array offset 0: type, 1: value
     */
    protected function getDefaultSetting()
    {
        list($default_type, $default) = parent::getDefaultSetting();

        if (is_nullorempty($default_type)) {
            $default = null;
        }

        return [$default_type, $default];
    }
}
