<?php

namespace Exceedone\Exment\Services\FormSetting\FormColumn;

use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Services\FormSetting\FormBlock\BlockBase;
use Encore\Admin\Widgets\Form as WidgetForm;

/**
 */
abstract class ColumnBase
{
    /**
     * @var CustomFormColumn
     */
    protected $custom_form_column;


    /**
     * Whether is selected column.
     *
     * If suggest and already selected as column, set true.
     * Otherwise, set false.
     *
     * @var bool
     */
    protected $isSelected = false;

    public function __construct(CustomFormColumn $custom_form_column)
    {
        $this->custom_form_column = $custom_form_column;
    }

    public static function make(CustomFormColumn $custom_form_column): ?ColumnBase
    {
        switch (array_get($custom_form_column, 'form_column_type', FormColumnType::COLUMN)) {
            case FormColumnType::COLUMN:
                return Column::make($custom_form_column);

            case FormColumnType::OTHER:
                return OtherBase::make($custom_form_column);
        }

        return null;
    }


    /**
     * Get object using form_column_type
     *
     * @return self
     */
    public static function makeByParams($form_column_type, $form_column_target_id, $header_column_name = null): ColumnBase
    {
        $form_column = new CustomFormColumn();
        $form_column->form_column_type = $form_column_type;
        $form_column->form_column_target_id = $form_column_target_id;

        // get form_column_id from request
        preg_match("/\[custom_form_columns\]\[(?<id>\d+)\]/iu", $header_column_name, $match);
        if ($match) {
            $form_column->id = $match['id'];
        }

        return static::make($form_column);
    }


    /**
     * Get the value of custom_form_column
     *
     * @return  CustomFormColumn
     */
    public function getCustomFormColumn()
    {
        return $this->custom_form_column;
    }


    /**
     * Get items for display
     *
     * @return array
     */
    public function getItemsForDisplay(): array
    {
        return [
            'form_column_type' => $this->custom_form_column->form_column_type ?? FormColumnType::COLUMN,
            'row_no' => $this->custom_form_column->row_no ?? 1,
            'column_no' => $this->custom_form_column->column_no ?? 1,
            'width' => $this->custom_form_column->width ?? 1,
            'form_column_target_id' => $this->custom_form_column->form_column_target_id ?? null,

            'options' =>  collect($this->custom_form_column->options ?? [])->toJson(),

            'is_select_table' => $this->isSelectTable(),
            'required' => $this->isRequired(),
            'column_view_name' => $this->getColumnViewName(),
            'header_column_name' => $this->getHtmlHeaderName(),
            'toggle_key_name' => make_uuid(),
            'validation_rules' => collect($this->getValidationRules())->toJson(),
            'has_custom_forms' => $this->isSelected,
            'delete_flg' => $this->custom_form_column->delete_flg ? 1 : 0,
            'use_setting' => $this->useSetting(),

            'font_awesome' => $this->getFontAwesomeClass(),

            'option_labels' => $this->getOptionLabels(),
            'option_labels_definitions' => collect($this->getOptionLabelsDefinitions())->toJson(),
        ];
    }



    public function isSelected(bool $isSelected)
    {
        $this->isSelected = $isSelected;
        return $this;
    }


    /**
     * Get html header name
     *
     * @return string
     */
    protected function getHtmlHeaderName()
    {
        $key = $this->custom_form_column['id'] ?? $this->custom_form_column->request_key ?? 'NEW__'.make_uuid();
        // add header name
        return "[custom_form_columns][{$key}]";
    }

    public function isSelectTable(): bool
    {
        return false;
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
            if (array_value_exists($difinition, $result)) {
                continue;
            }

            // hard coding
            $value = $options[$key];
            if ($key == 'required') {
                if (!boolval($value)) {
                    continue;
                }
            }
            if ($key == 'field_label_type') {
                if ($value == 'form_default') {
                    continue;
                }
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
        $result['field_label_type'] = exmtrans('custom_form.form_label_type') . ':' . exmtrans('custom_form.setting_available');
        ;

        // get field display type
        foreach (['read_only', 'view_only', 'hidden', 'internal'] as $key) {
            $result[$key] = exmtrans("custom_form.$key");
        }

        foreach (['default_type', 'default'] as $key) {
            $result[$key] = exmtrans("custom_column.options.default") . ':' . exmtrans('custom_form.setting_available');
        }

        $result['relation_filter_target_column_id'] = exmtrans('custom_form.relation_filter') . ':' . exmtrans('custom_form.setting_available');
        $result['changedata_column_id'] = exmtrans('custom_form.changedata') . ':' . exmtrans('custom_form.setting_available');

        return $result;
    }

    /**
     * Whether using setting
     *
     * @return boolean
     */
    public function useSetting(): bool
    {
        return true;
    }


    abstract public function getFontAwesomeClass(): ?string;

    /**
     * Get validation rules for jquery
     *
     * @return array
     */
    public function getValidationRules(): array
    {
        return [];
    }

    /**
     * Get column's view name
     *
     * @return string|null
     */
    abstract public function getColumnViewName(): ?string;


    /**
     * Whether this column is required
     *
     * @return boolean
     */
    abstract public function isRequired(): bool;

    /**
     * Get setting modal form
     *
     * @param BlockBase $block_item
     * @param array $parameters
     * @return WidgetForm|null
     */
    abstract public function getSettingModalForm(BlockBase $block_item, array $parameters): ?WidgetForm;

    /**
     * prepare saving option.
     *
     * @return array
     */
    abstract public function prepareSavingOptions(array $options): array;
}
