<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\Enums\FormatCalledType;
use Exceedone\Exment\Enums\PluginType;
use Exceedone\Exment\Model\Plugin;
use Encore\Admin\Form;
use Illuminate\Support\Str;

class CustomText extends CustomItem
{
    protected function getAdminFieldClass()
    {
        return null;
    }

    public function setCustomValue($custom_value)
    {
        $this->custom_value = $this->getTargetCustomValue($custom_value);
        if (isset($custom_value)) {
            $format_type = $this->custom_column->getOption('custom_text_format_type');
            if ($format_type == 'format') {
                // get format
                $format = array_get($this->custom_column->options, "custom_text_format");
                $this->value = replaceTextFromFormat($format, $custom_value);
            } elseif (Str::isUuid($format_type)) {
                $plugin = Plugin::getPluginByUUID($format_type);
                $class = $plugin->getClass(PluginType::FORMAT, [
                    'custom_table' => $this->custom_table,
                    'custom_value' => $this->custom_value,
                    'calledType' => FormatCalledType::COLUMN,
                ]);
                $this->value = $class->format();
            }
            $this->id = array_get($custom_value, 'id');
        }

        $this->prepare();

        return $this;
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
     * Set Custom Column Option Form. Using laravel-admin form option
     * https://laravel-admin.org/docs/#/en/model-form-fields
     *
     * @param Form $form
     * @return void
     */
    public function setCustomColumnOptionForm(&$form)
    {
        $options = [
            'format' => exmtrans("custom_column.options.custom_text_type_format"),
        ];
        $plugins = Plugin::getAccessableByPluginTypes(PluginType::FORMAT);
        foreach($plugins as $plugin) {
            $options[$plugin->uuid] = exmtrans('custom_column.options.plugin_format', $plugin->plugin_view_name);
        }
        // custom_text_format_type
        $form->select('custom_text_format_type', exmtrans("custom_column.options.custom_text_format_type"))
            ->required()
            ->options($options)
            ->attribute(['data-filtertrigger' =>true]);

        // set manual
        $manual_url = getManualUrl('params');
        $form->text('custom_text_format', exmtrans("custom_column.options.custom_text_format"))
            ->attribute(['data-filter' => json_encode([
                ['parent' => 1, 'key' => 'options_custom_text_format_type', 'value' => 'format'],
            ])])
            ->help(sprintf(exmtrans("custom_column.help.custom_text_format"), $manual_url))
        ;
    }
}
