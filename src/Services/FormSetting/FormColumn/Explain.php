<?php

namespace Exceedone\Exment\Services\FormSetting\FormColumn;

use Exceedone\Exment\Services\FormSetting\FormBlock\BlockBase;
use Encore\Admin\Widgets\Form as WidgetForm;

/**
 */
class Explain extends Header
{
    /**
     * Get setting modal form
     *
     * @return WidgetForm
     */
    public function getSettingModalForm(BlockBase $block_item, array $parameters): WidgetForm
    {
        $form = new WidgetForm($parameters);

        $form->textarea('text', exmtrans('custom_form.text'))
            ->rows(6)
            ->required();

        return $form;
    }

    public function getFontAwesomeClass(): ?string
    {
        return 'fa-align-justify';
    }
}
