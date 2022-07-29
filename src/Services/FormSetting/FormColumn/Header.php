<?php

namespace Exceedone\Exment\Services\FormSetting\FormColumn;

use Exceedone\Exment\Services\FormSetting\FormBlock\BlockBase;
use Encore\Admin\Widgets\Form as WidgetForm;

/**
 */
class Header extends OtherBase
{
    /**
     * Get setting modal form
     *
     * @return WidgetForm
     */
    public function getSettingModalForm(BlockBase $block_item, array $parameters): WidgetForm
    {
        $form = new WidgetForm($parameters);

        $form->text('text', exmtrans('custom_form.text'))
            ->required();

        $form->switchbool('append_hr', exmtrans('custom_form.append_hr'))
            ->help(exmtrans('custom_form.help.append_hr'));

        return $form;
    }


    /**
     * prepare saving option.
     *
     * @return array
     */
    public function prepareSavingOptions(array $options): array
    {
        return array_filter($options, function ($option, $key) {
            return in_array($key, [
                'text',
                'append_hr',
            ]);
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Get validation rules for jquery
     *
     * @return array
     */
    public function getValidationRules(): array
    {
        return ['text' => 'required'];
    }

    public function getFontAwesomeClass(): ?string
    {
        return 'fa-header';
    }
}
