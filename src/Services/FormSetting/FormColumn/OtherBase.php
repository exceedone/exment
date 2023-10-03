<?php

namespace Exceedone\Exment\Services\FormSetting\FormColumn;

use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Services\FormSetting\FormBlock\BlockBase;
use Encore\Admin\Widgets\Form as WidgetForm;

/**
 */
abstract class OtherBase extends ColumnBase
{
    public static function make(CustomFormColumn $custom_form_column): ?ColumnBase
    {
        $column_form_column_name = FormColumnType::getOption(['id' => array_get($custom_form_column, 'form_column_target_id')])['column_name'] ?? null;
        switch ($column_form_column_name) {
            case 'header':
                return new Header($custom_form_column);
            case 'explain':
                return new Explain($custom_form_column);
            case 'html':
            case 'exhtml':
                return new Html($custom_form_column);
            case 'image':
                return new Image($custom_form_column);
            case 'hr':
                return new Hr($custom_form_column);
        }

        return null;
    }

    /**
     * Get object for suggest
     *
     * @param $form_column_type_id
     * @return ColumnBase
     */
    public static function makeBySuggest($form_column_type_id): ColumnBase
    {
        $form_column = new CustomFormColumn();
        $form_column->form_column_type = FormColumnType::OTHER;
        $form_column->form_column_target_id = $form_column_type_id;

        return static::make($form_column);
    }


    /**
     * Get column's view name
     *
     * @return string|null
     */
    public function getColumnViewName(): ?string
    {
        // get column name
        $column_form_column_name = FormColumnType::getOption(['id' => array_get($this->custom_form_column, 'form_column_target_id')])['column_name'] ?? null;
        return exmtrans("custom_form.form_column_type_other_options.$column_form_column_name");
    }

    /**
     * Whether this column is required
     *
     * @return boolean
     */
    public function isRequired(): bool
    {
        return false;
    }

    /**
     * Get setting modal form
     *
     * @param BlockBase $block_item
     * @param array $parameters
     * @return WidgetForm|null
     */
    public function getSettingModalForm(BlockBase $block_item, array $parameters): ?WidgetForm
    {
        $form = new WidgetForm($parameters);
        return $form;
    }


    /**
     * prepare saving option.
     *
     * @return array
     */
    public function prepareSavingOptions(array $options): array
    {
        return $options;
    }


    /**
     * Get label for option
     *
     * @return array
     */
    public function getOptionLabels(): array
    {
        $options = $this->custom_form_column->options ?? [];
        $difinitions = $this->getOptionLabelsDefinitions();

        $result = [];
        foreach ($difinitions as $key => $difinition) {
            if (!array_key_value_exists($key, $options)) {
                continue;
            }

            $result[] = $difinition;
        }

        return $result;
    }

    /**
     * Get option labels difinitions. for getting label, and js
     *
     * @return array
     */
    public function getOptionLabelsDefinitions(): array
    {
        $result = [];
        $result['required'] = exmtrans('common.required');

        // get field display type
        foreach (['image', 'text', 'html'] as $key) {
            $result[$key] = exmtrans('custom_form.setting_available');
        }

        return $result;
    }
}
