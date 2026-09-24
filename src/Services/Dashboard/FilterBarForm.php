<?php

namespace Exceedone\Exment\Services\Dashboard;

use Encore\Admin\Facades\Admin;
use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Model\CustomTable;

/**
 * The "dashboard filter bar" section of the dashboard setting form: the source table and
 * the filter items (column, display name, default value, target boxes). Bound to the
 * Dashboard model's filter_bar_table / filter_bar_dims virtual attributes; the table's
 * client-side behaviour is dashboard.js (ExmentDashboardForm).
 */
final class FilterBarForm
{
    /**
     * @param \Encore\Admin\Form $form
     * @param \Exceedone\Exment\Model\Dashboard|null $model  null on a new dashboard
     */
    public static function build($form, $model): void
    {
        $form->exmheader(exmtrans('dashboard.filter_bar.header'))->hr();
        $form->descriptionHtml(exmtrans('dashboard.filter_bar.description'));

        $columnsUrl = admin_urls('dashboard', 'filter_bar_columns');
        $form->select('filter_bar_table', exmtrans('dashboard.filter_bar.source_table'))
            ->options(CustomTable::filterList()->pluck('table_view_name', 'table_name')->toArray())
            ->help(exmtrans('dashboard.filter_bar.help.source_table'))
            // repoints every item's column select at the newly chosen table
            ->attribute(['data-linkage' => json_encode(['column' => $columnsUrl])]);

        $valuesUrl = admin_urls('dashboard', 'filter_bar_values');
        $dashboardId = $model ? $model->id : '';
        $columns = self::columnOptions($model ? $model->getOption('filter_bar.source_table') : null);
        $boxes = self::boxOptions($model);

        $form->hasManyJsonTable('filter_bar_dims', exmtrans('dashboard.filter_bar.dims'), function ($form) use ($columns, $boxes) {
            $form->select('column', exmtrans('dashboard.filter_bar.dim_column'))
                ->options($columns)
                // the choices are reloaded client-side when the table changes: validate
                // against the table the request actually carries
                ->validationOptions(function () {
                    return self::columnOptions(request()->input('filter_bar_table'));
                });
            $form->text('label', exmtrans('dashboard.filter_bar.dim_label'));
            // posts the stored value(s) as text ("a,b" for a list column, "min~max" for a range
            // column); dashboard.js (ExmentDashboardForm) draws a picker or from / to inputs in
            // front of it, fetched from dashboard/filter_bar_values
            $form->text('default', exmtrans('dashboard.filter_bar.dim_default'))
                ->help(exmtrans('dashboard.filter_bar.help.dim_default'));
            $form->multipleSelect('targets', exmtrans('dashboard.filter_bar.dim_targets'))
                ->options($boxes)
                ->help(exmtrans('dashboard.filter_bar.help.dim_targets'));
        })->descriptionHtml('<span class="help-block"><i class="fa fa-info-circle"></i>&nbsp;' . exmtrans('dashboard.filter_bar.help.dims') . '</span>');

        // behaviour of the items table — a "+ new" row gets its column choices, the デフォルト値
        // cell draws a picker or from / to inputs in front of its text input — lives in
        // dashboard.js (ExmentDashboardForm, loaded on every admin page); only what the page
        // cannot know is passed: the linkage endpoints, the dashboard id, two texts
        Admin::script('ExmentDashboardForm.init(' . json_encode([
            'columnsUrl' => $columnsUrl,
            'valuesUrl' => $valuesUrl,
            'dashboardId' => (string) $dashboardId,
            'lang' => [
                'range_from' => exmtrans('dashboard.filter_bar.range_from'),
                'range_to' => exmtrans('dashboard.filter_bar.range_to'),
            ],
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . ');');
    }

    /**
     * Filter column choices of a table, as column_name => "label (column_name)".
     *
     * @return array<string, string>
     */
    public static function columnOptions($tableName): array
    {
        $table = is_nullorempty($tableName) ? null : CustomTable::getEloquent($tableName);
        if ($table === null) {
            return [];
        }
        $options = [];
        foreach ($table->custom_columns as $column) {
            $options[$column->column_name] = $column->column_view_name . ' (' . $column->column_name . ')';
        }
        return $options;
    }

    /**
     * Targeting choices: this dashboard's chart boxes (the only boxes that apply filters).
     *
     * @return array<string, string> suuid => "name (row-column)"
     */
    private static function boxOptions($model): array
    {
        $options = [];
        if ($model) {
            foreach ($model->dashboard_boxes as $box) {
                if ($box->dashboard_box_type == DashboardBoxType::CHART) {
                    $options[$box->suuid] = $box->dashboard_box_view_name . ' (' . $box->row_no . '-' . $box->column_no . ')';
                }
            }
        }
        return $options;
    }
}
