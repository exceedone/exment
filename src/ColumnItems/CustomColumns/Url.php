<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\UrlTagType;
use Encore\Admin\Form;
use Encore\Admin\Form\Field;

class Url extends CustomItem
{
    /**
     * get html(for display)
     * *this function calls from non-escaping value method. So please escape if not necessary unescape.
     */
    protected function _html($v)
    {
        $value = $this->_value($v);
        $url = $this->_value($v);

        $value = boolval(array_get($this->options, 'grid_column')) ? get_omitted_string($value) : $value;

        return \Exment::getUrlTag($url, $value, UrlTagType::BLANK);
    }

    protected function getAdminFieldClass()
    {
        return Field\Url::class;
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
     * Set Custom Column Option default value Form. Using laravel-admin form option
     * https://laravel-admin.org/docs/#/en/model-form-fields
     *
     * @param Form $form
     * @return void
     */
    public function setCustomColumnDefaultValueForm(&$form, bool $asCustomForm = false)
    {
        $form->url('default', exmtrans("custom_column.options.default"))
            ->help(exmtrans("custom_column.help.default"));
    }
}
