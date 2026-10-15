<?php

namespace Exceedone\Exment\Controllers;

use ExmentAdminCore\Admin\Auth\Permission as Checker;
use ExmentAdminCore\Admin\Form;
use ExmentAdminCore\Admin\Grid;
use Exceedone\Exment\ColumnItems\GridCellStyle;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Model\CellStylePreset;

/**
 * The cell style preset library as one list.
 *
 * The modal on the column and view screens edits one preset in the middle
 * of another job. This screen is the librarian's view: every preset of the
 * installation on one page - the rows seeded at migration next to the
 * hand-made ones - with the same fields the modal offers, plus the order
 * the pickers list them in.
 */
class CellStylePresetSettingController extends AdminControllerBase
{
    use HasResourceActions;

    public function __construct()
    {
        $this->setPageInfo(exmtrans('cell_style_preset.header'), exmtrans('cell_style_preset.header'), exmtrans('cell_style_preset.description'), 'fa-paint-brush');
    }

    /**
     * Execute an action on the controller.
     *
     * A preset repaints every list that picked it, on every table at once,
     * so the library is curated with system permission - a table owner
     * still creates and edits their own presets through the modal.
     *
     * @param  string  $method
     * @param  array<string, mixed>   $parameters
     * @return \Symfony\Component\HttpFoundation\Response
     */
    // @phpstan-ignore-next-line
    public function callAction($method, $parameters)
    {
        $user = \Exment::user();
        if (!isset($user) || !$user->hasPermission(Permission::SYSTEM)) {
            Checker::error();
            return response('');
        }

        return $this->{$method}(...array_values($parameters));
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new CellStylePreset());

        $grid->column('preset_name', exmtrans('cell_style_preset.preset_name'))->sortable();

        $grid->column('suuid', exmtrans('cell_style_preset.preview'))->display(function () {
            /** @var CellStylePreset $this */
            return CellStylePresetSettingController::sampleHtml($this->definition());
        })->escape(false);

        $grid->column('column_types', exmtrans('cell_style_preset.column_types'))->display(function ($column_types) {
            $types = CellStylePreset::filterColumnTypes($column_types);
            if (empty($types)) {
                return exmtrans('cell_style_preset.column_types_all');
            }

            return collect($types)->map(function ($type) {
                return exmtrans('custom_column.column_type_options.' . $type);
            })->implode(', ');
        });

        $grid->column('order', exmtrans('custom_view.order'))->sortable()->editable();
        $grid->column('updated_at', trans('admin.updated_at'))->sortable();

        // The pickers list presets in this same order, so the screen that
        // manages them reads the same way the pickers do.
        $grid->model()->orderBy('order')->orderBy('preset_name');

        $grid->disableExport();
        $grid->actions(function (Grid\Displayers\Actions $actions) {
            $actions->disableView();
        });
        $grid->filter(function ($filter) {
            $filter->disableIdFilter();
            $filter->like('preset_name', exmtrans('cell_style_preset.preset_name'));
        });

        return $grid;
    }

    /**
     * Make a form builder.
     *
     * The same fields the modal editor offers, as a plain setting form. The
     * colors are text fields on purpose: the bundled color picker rounds a
     * hex value on its way through HSB, which would rewrite a saved color
     * one shade off every time the form is opened.
     *
     * @param mixed $id
     * @return Form
     */
    protected function form($id = null)
    {
        $form = new Form(new CellStylePreset());

        if (isset($id)) {
            $form->display('suuid', exmtrans('cell_style_preset.key'));
        }

        $form->text('preset_name', exmtrans('cell_style_preset.preset_name'))
            ->required()
            ->rules('max:40');

        $color_rule = ['nullable', 'regex:/^#?([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'];

        $form->embeds('options', exmtrans('custom_column.options.header'), function ($form) use ($color_rule) {
            $form->select('grid_style', exmtrans('custom_column.options.grid_style'))
                ->options(GridCellStyle::getStyleOptions())
                ->default(GridCellStyle::STYLE_PLAIN)
                ->disableClear()
                ->help(exmtrans('custom_column.help.grid_style'));

            foreach (['grid_color', 'grid_bg_color', 'grid_border_color'] as $color_key) {
                $form->text($color_key, exmtrans('custom_column.options.' . $color_key))
                    ->rules($color_rule)
                    ->attribute(['placeholder' => '#3c8dbc'])
                    ->help(exmtrans('custom_column.help.' . $color_key));
            }

            $form->select('grid_font_weight', exmtrans('custom_column.options.grid_font_weight'))
                ->options(GridCellStyle::getFontWeightOptions())
                ->help(exmtrans('custom_column.help.grid_font_weight'));

            $form->text('grid_icon', exmtrans('custom_column.options.grid_icon'))
                ->rules(['nullable', 'regex:/^fa-[0-9a-zA-Z-]+$/'])
                ->attribute([
                    'placeholder' => 'fa-check',
                    'data-cellstyle-iconpicker' => '1',
                    'data-cellstyle-iconlabels' => json_encode(GridCellStyle::iconPickerLabels()),
                ])
                ->help(exmtrans('custom_column.help.grid_icon'));

            $form->switchbool('grid_nowrap', exmtrans('custom_column.options.grid_nowrap'))
                ->help(exmtrans('custom_column.help.grid_nowrap'));

            // Same table as the column screen, minus the names: a preset is
            // shared, so the values it colors are whatever the columns that
            // pick it up happen to store.
            $form->textarea('grid_value_colors', exmtrans('custom_column.options.grid_value_colors'))
                ->rows(4)
                ->attribute([
                    'data-cellstyle-valuecolors' => '1',
                    'data-cellstyle-labels' => json_encode(GridCellStyle::valueColorLabels()),
                ])
                ->help(exmtrans('custom_column.help.grid_value_colors'));
        });

        $form->multipleSelect('column_types', exmtrans('cell_style_preset.column_types'))
            ->options(ColumnType::transArray('custom_column.column_type_options'))
            ->help(exmtrans('cell_style_preset.column_types_hint'));

        $form->number('order', exmtrans('custom_view.order'))
            ->default(0)
            ->rules('integer')
            ->help(exmtrans('cell_style_preset.order_help'));

        $form->disableEditingCheck(false);

        return $form;
    }

    /**
     * One preset drawn in its own style, for the list.
     *
     * Server-side twin of the dropdown chips cellstyle_preset.js draws: the
     * list page has no picker to borrow them from, and a name alone says
     * nothing about a look.
     *
     * @param array<string, mixed> $definition
     * @return string
     */
    public static function sampleHtml(array $definition): string
    {
        $options = $definition['options'];
        $name = strval($definition['name']);
        $style = strval(array_get($options, 'grid_style')) ?: GridCellStyle::STYLE_TEXT;

        $rows = GridCellStyle::parseValueColors(array_get($options, 'grid_value_colors'));
        $first = collect($rows)->first() ?: [];
        $own_color = GridCellStyle::normalizeColor(array_get($options, 'grid_color'));
        $color = array_get($first, 'color') ?? $own_color ?? GridCellStyle::PALETTE[0];
        $weight = strval(array_get($options, 'grid_font_weight'));
        $weight = in_array($weight, ['400', '600', '700'], true) ? $weight : null;

        $icon = GridCellStyle::normalizeIcon(array_get($options, 'grid_icon'));
        $icon_html = isset($icon) ? '<i class="fa ' . e($icon) . '"></i>' : '';

        if ($style === GridCellStyle::STYLE_BAR) {
            return '<span class="exm-cell-bar" style="max-width:120px">'
                . '<span class="exm-cell-bar-track"><span class="exm-cell-bar-fill" style="width:70%;background-color:' . e($color) . '"></span></span>'
                . '<span class="exm-cell-bar-txt" style="color:' . e($color) . '">70%</span>'
                . '</span>';
        }

        if ($style === GridCellStyle::STYLE_AVATAR) {
            $initial = mb_substr($name, 0, 1);
            return '<span class="exm-cell-avatar"><span class="exm-cell-av" style="background-color:'
                . e($own_color ?? GridCellStyle::hashColor($name)) . '">' . e($initial) . '</span>' . e($name) . '</span>';
        }

        $css = [];
        if (isset($weight)) {
            $css[] = 'font-weight:' . $weight;
        }

        if ($style === GridCellStyle::STYLE_CELL) {
            $bg = GridCellStyle::normalizeColor(array_get($options, 'grid_bg_color'));
            if (isset($own_color)) {
                $css[] = 'color:' . $own_color;
            }
            $css[] = 'background-color:' . ($bg ?? '#eef4fa');
            return '<span class="exm-cellstyle-sample-cell" style="' . e(implode(';', $css)) . '">' . $icon_html . e($name) . '</span>';
        }

        $mark = '';
        if ($style === GridCellStyle::STYLE_BADGE) {
            $fill = array_get($first, 'background')
                ?? array_get($first, 'color')
                ?? GridCellStyle::normalizeColor(array_get($options, 'grid_bg_color'))
                ?? $color;
            $css[] = 'background-color:' . $fill;
            if (isset($own_color)) {
                $css[] = 'color:' . $own_color;
            }
        } elseif (in_array($style, [GridCellStyle::STYLE_TAG, GridCellStyle::STYLE_PILL], true)) {
            $css[] = 'color:' . $color;
            $css[] = 'background-color:' . (GridCellStyle::normalizeColor(array_get($options, 'grid_bg_color')) ?? GridCellStyle::rgba($color, 0.12));
            $css[] = 'border-color:' . (GridCellStyle::normalizeColor(array_get($options, 'grid_border_color')) ?? GridCellStyle::rgba($color, 0.32));
        } elseif (in_array($style, [GridCellStyle::STYLE_DOT, GridCellStyle::STYLE_LVL], true)) {
            $css[] = 'color:' . $color;
            $mark = '<span class="exm-cell-mark" style="background-color:' . e($color) . '"></span>';
        } else {
            // text and mono carry only their colors
            if (isset($own_color) || !empty($rows)) {
                $css[] = 'color:' . $color;
            }
        }

        return '<span class="exm-cell-' . e($style) . '" style="' . e(implode(';', $css)) . '">'
            . $mark . $icon_html . e($name) . '</span>';
    }
}
