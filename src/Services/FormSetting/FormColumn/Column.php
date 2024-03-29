<?php

namespace Exceedone\Exment\Services\FormSetting\FormColumn;

use Encore\Admin\Form;
use Encore\Admin\Widgets\Form as WidgetForm;
use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Enums\FormLabelType;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Services\FormSetting\FormBlock\BlockBase;
use Exceedone\Exment\Services\FormSetting\FormBlock\RelationBase;
use Illuminate\Support\Collection;

/**
 * Custom column
 */
class Column extends ColumnBase
{
    /**
     * @var CustomColumn|null
     */
    protected $custom_column;

    public function __construct(CustomFormColumn $custom_form_column)
    {
        parent::__construct($custom_form_column);
        $this->custom_column = $custom_form_column->custom_column_cache;

        // get from form_column_target_id
        if (!isset($this->custom_column)) {
            $this->custom_column = CustomColumn::getEloquent(array_get($custom_form_column, 'form_column_target_id'));
        }
    }

    public static function make(CustomFormColumn $custom_form_column): ColumnBase
    {
        $custom_column = $custom_form_column->custom_column_cache;
        if (ColumnType::isSelectTable(array_get($custom_column, 'column_type'))) {
            return new SelectTable($custom_form_column);
        }
        return new Column($custom_form_column);
    }

    /**
     * Get object for suggest
     *
     * @param CustomColumn $custom_column
     * @return ColumnBase
     */
    public static function makeBySuggest(CustomColumn $custom_column): ColumnBase
    {
        $form_column = new CustomFormColumn();
        $form_column->form_column_type = FormColumnType::COLUMN;
        $form_column->form_column_target_id = $custom_column->id;

        return static::make($form_column);
    }


    /**
     * Get column's view name
     *
     * @return string|null
     */
    public function getColumnViewName(): ?string
    {
        if (!isset($this->custom_column)) {
            return null;
        }
        return $this->custom_column->column_view_name;
    }


    /**
     * Whether this column is required
     *
     * @return boolean
     */
    public function isRequired(): bool
    {
        return boolval(array_get($this->custom_form_column, 'required')) || boolval(array_get($this->custom_column, 'required'));
    }


    /**
     * prepare saving option.
     *
     * @return array
     */
    public function prepareSavingOptions(array $options): array
    {
        // convert field_showing_type
        if (!is_null($key = $this->convertFieldDisplayType($options))) {
            $options[$key] = 1;
        }
        return array_filter($options, function ($option, $key) {
            return in_array($key, $this->prepareSavingOptionsKeys());
        }, ARRAY_FILTER_USE_BOTH);
    }


    /**
     * Get prepare options keys
     *
     * @return array
     */
    protected function prepareSavingOptionsKeys()
    {
        return [
            'form_column_view_name',
            'field_label_type',
            'required',
            'view_only',
            'read_only',
            'hidden',
            'internal',
            'default_type',
            'default',
            'help',
            'changedata_target_column_id',
            'changedata_column_id',
        ];
    }


    /**
     * Convert "field_showing_type" to 'view_only','read_only','hidden' params
     *
     * @param array $options
     * @return string
     */
    protected function convertFieldDisplayType(array $options): ?string
    {
        foreach (['view_only','read_only','hidden','internal'] as $key) {
            if (isMatchString($key, array_get($options, 'field_showing_type'))) {
                return $key;
            }
        }

        return null;
    }


    /**
     * Get setting modal form
     *
     * @return WidgetForm
     */
    public function getSettingModalForm(BlockBase $block_item, array $parameters): WidgetForm
    {
        $form = new WidgetForm($parameters);
        $column_item = $this->custom_column->column_item;

        $form->text('form_column_view_name', exmtrans('custom_form.form_column_view_name'))
            ->help(exmtrans('custom_form.help.form_column_view_name'))
            ->default($this->custom_column->column_view_name ?? null);

        $form->radio('field_label_type', exmtrans('custom_form.form_label_type'))
            ->options(FormLabelType::transArrayFilter('custom_form.form_label_type_options', FormLabelType::getFieldLabelTypes()))
            ->help(exmtrans('custom_form.help.field_label_type'))
            ->default(function () use ($parameters) {
                return array_get($parameters, 'field_label_type', FormLabelType::FORM_DEFAULT);
            });

        $form->radio('field_showing_type', exmtrans('custom_form.field_showing_type'))->options([
            'default' => exmtrans('custom_form.field_default'),
            'read_only' => exmtrans('custom_form.read_only'),
            'view_only' => exmtrans('custom_form.view_only'),
            'hidden' => exmtrans('custom_form.hidden'),
            'internal' => exmtrans('custom_form.internal'),
        ])->help(exmtrans('custom_form.help.field_showing_type') . \Exment::getMoreTag('form', 'custom_form.items_detail'))
        ->default(function () use ($parameters) {
            foreach (['read_only', 'view_only', 'hidden', 'internal'] as $key) {
                if (boolval(array_get($parameters, $key, false))) {
                    return $key;
                }
            }
            return 'default';
        });

        if ($this->custom_column->required) {
            $form->display('required', exmtrans('custom_form.required'))
                ->displayText(exmtrans('custom_form.message.required_as_column'));
        } else {
            $form->switchbool('required', exmtrans('custom_form.required'))
                ->help(exmtrans('custom_form.help.required'));
        }

        $column_item->setCustomColumnDefaultValueForm($form, true);

        $form->text('help', exmtrans("custom_column.options.help"))->help(exmtrans("custom_column.help.help"));

        $selectColumns = $this->getSelectTableColumns($block_item)->filter(function ($selectColumn, $key) {
            return !isMatchString($key, $this->custom_column->id);
        });

        if ($selectColumns->count() > 0) {
            $form->exmheader(exmtrans('custom_form.changedata'))->hr();
            $form->description(sprintf(exmtrans('custom_form.help.changedata'), getManualUrl('form?id='.exmtrans('custom_form.changedata'))))->escape(false);

            $form->select('changedata_target_column_id', exmtrans('custom_form.changedata_target_column'))
                ->help(exmtrans('custom_form.changedata_target_column_when'))
                ->options($selectColumns);

            $form->select('changedata_column_id', exmtrans('custom_form.changedata_column'))
                ->help(exmtrans('custom_form.changedata_column_then'))
                ->options(function () use ($parameters) {
                    if (is_null($changedata_target_column_id = array_get($parameters, 'changedata_target_column_id'))) {
                        return [];
                    }

                    // get custom column
                    $custom_column = CustomColumn::getEloquent($changedata_target_column_id);
                    // if column_type is not select_table, return []
                    if (!ColumnType::isSelectTable(array_get($custom_column, 'column_type'))) {
                        return [];
                    }

                    // get select_target_table
                    $select_target_table = $custom_column->select_target_table;
                    if (!isset($select_target_table)) {
                        return [];
                    }
                    return $select_target_table->custom_columns_cache->pluck('column_view_name', 'id');
                });
        }

        return $form;
    }


    /**
     * Get select table's columns in this block.
     *
     * @return Collection
     */
    protected function getSelectTableColumns(BlockBase $block_item): Collection
    {
        if (!isset($this->custom_column)) {
            return collect();
        }

        $custom_table = $this->custom_column->custom_table_cache;
        if (!$custom_table) {
            return collect();
        }

        $custom_columns = $custom_table->custom_columns_cache->filter(function ($custom_column) {
            return ColumnType::isSelectTable(array_get($custom_column, 'column_type'));
        });

        // if form block type is 1:n or n:n, get parent tables columns too.
        if ($block_item instanceof RelationBase) {
            $custom_columns = $custom_columns->merge(
                $block_item->getCustomTable()->custom_columns_cache
            );
        }

        $result = [];
        foreach ($custom_columns as $custom_column) {
            $target_table = $custom_column->select_target_table;
            if (!isset($target_table)) {
                /** @var Collection $collection */
                $collection =  collect($result);
                return $collection;
            }

            // get custom table
            $custom_table = $custom_column->custom_table_cache;
            // set table name if not $form_block_target_table_id and custom_table_eloquent's id
            $form_block_target_table_id = array_get($block_item->getCustomFormBlock(), 'form_block_target_table_id');
            if (!isMatchString($custom_table->id, $form_block_target_table_id)) {
                $select_table_column_name = sprintf('%s:%s', $custom_table->table_view_name, array_get($custom_column, 'column_view_name'));
            } else {
                $select_table_column_name = array_get($custom_column, 'column_view_name');
            }
            // get select_table, user, organization columns
            $result[array_get($custom_column, 'id')] = $select_table_column_name;
        }

        /** @var Collection $collection */
        $collection = collect($result);
        return $collection;
    }


    public function getFontAwesomeClass(): ?string
    {
        return $this->custom_column->getFontAwesomeClass();
    }
}
