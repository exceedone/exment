<?php

namespace Exceedone\Exment\Services\Dashboard;

use Encore\Admin\Facades\Admin;
use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Model\CustomTable;

/**
 * The "dashboard filter bar" section of the dashboard setting form: the source table and
 * the filter items (column, display name, target boxes). Bound to the Dashboard model's
 * filter_bar_table / filter_bar_dims virtual attributes.
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
            $form->multipleSelect('targets', exmtrans('dashboard.filter_bar.dim_targets'))
                ->options($boxes)
                ->help(exmtrans('dashboard.filter_bar.help.dim_targets'));
        })->descriptionHtml('<span class="help-block"><i class="fa fa-info-circle"></i>&nbsp;' . exmtrans('dashboard.filter_bar.help.dims') . '</span>');

        // A row added with "+ new" is cloned from a template rendered before any table was
        // picked: fill its column select from a sibling row, or from the linkage endpoint.
        Admin::script(<<<EOT
$('#has-many-table-filter_bar_dims').on('admin_hasmany_row_change', function (e) {
    if (!$(e.target).closest('.add').length) { return; }
    var empty = $('#has-many-table-filter_bar_dims-table tbody tr:visible').last().find('select.column').filter(function () { return !this.value; });
    if (!empty.length) { return; }
    var fill = function (html) { empty.each(function () { $(this).html(html).val('').trigger('change.select2'); }); };
    var loaded = $('#has-many-table-filter_bar_dims-table select.column').not(empty).filter(function () { return this.options.length > 1; }).first();
    if (loaded.length) { fill(loaded.html()); return; }
    var table = $('select[name="filter_bar_table"]').val();
    if (!table) { return; }
    $.get('{$columnsUrl}', { q: table }, function (data) {
        var html = '<option value=""></option>';
        $.each(data, function (i, d) { html += '<option value="' + d.id + '">' + $('<div/>').text(d.text).html() + '</option>'; });
        fill(html);
    });
});
EOT);
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
