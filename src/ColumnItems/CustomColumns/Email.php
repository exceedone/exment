<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\Enums\FilterOption;
use Encore\Admin\Form;
use Encore\Admin\Form\Field;

class Email extends CustomItem
{
    protected function getAdminFieldClass()
    {
        return Field\Email::class;
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
        $form->email('default', exmtrans("custom_column.options.default"))
            ->help(exmtrans("custom_column.help.default"));
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
}
