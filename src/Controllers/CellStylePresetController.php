<?php

namespace Exceedone\Exment\Controllers;

use Illuminate\Http\Request;
use Exceedone\Exment\ColumnItems\GridCellStyle;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Model\CellStylePreset;
use Exceedone\Exment\Model\CustomTable;

/**
 * The cell style preset library, as the setting screens use it.
 *
 * A preset is not owned by a table - the same "status pill" is meant to be
 * picked on any table that has a status column. The table in the url is
 * therefore not a filter but the permission being checked: every screen that
 * can open this one already required table settings permission on it, and
 * asking for the same thing here keeps the check on the server where a
 * hidden button cannot be the only guard.
 */
class CellStylePresetController extends AdminControllerTableBase
{
    /**
     * Execute an action on the controller.
     *
     * @param  string  $method
     * @param  array<string, mixed>   $parameters
     * @return \Symfony\Component\HttpFoundation\Response
     */
    // @phpstan-ignore-next-line
    public function callAction($method, $parameters)
    {
        if (!isset($this->custom_table)) {
            return abortJson(404);
        }
        if (!$this->custom_table->hasPermission(Permission::CUSTOM_TABLE)) {
            return abortJson(403);
        }
        if (!CellStylePreset::available()) {
            return abortJson(404);
        }

        return $this->{$method}(...array_values($parameters));
    }

    /**
     * All presets, for the picker and its previews.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request, $tableKey)
    {
        return response()->json([
            'presets' => CellStylePreset::getDefinitions(),
        ]);
    }

    /**
     * The preset editor, as modal body html.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function modal(Request $request, $tableKey)
    {
        $key = $request->get('key');
        $definition = CellStylePreset::findDefinition($key);

        // A built-in cannot be written to, so opening one is really "start a
        // new preset from these settings" - the editor says so by keeping the
        // update button out of reach and clearing the key.
        if (isset($definition) && $definition['builtin']) {
            $definition['key'] = null;
            $definition['name'] = sprintf(exmtrans('cell_style_preset.copy_of'), $definition['name']);
        }

        return getAjaxResponse([
            'body' => view('exment::cellstyle.modal', [
                'endpoint' => admin_urls('webapi', $this->custom_table->table_name, 'cellstylepreset'),
                'definition' => $definition,
                'style_options' => GridCellStyle::getStyleOptions(),
                'weight_options' => GridCellStyle::getFontWeightOptions(),
                'column_type_options' => ColumnType::transArray('custom_column.column_type_options'),
            ])->render(),
            'title' => exmtrans('cell_style_preset.modal_title'),
            'showSubmit' => false,
            'modalSize' => 'modal-lg',
        ]);
    }

    /**
     * Save a new preset.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function store(Request $request, $tableKey)
    {
        $name = static::readName($request);
        if (is_nullorempty($name)) {
            return abortJson(400, exmtrans('cell_style_preset.error.name_required'));
        }

        $preset = new CellStylePreset();
        $preset->preset_name = $name;
        $preset->column_types = CellStylePreset::filterColumnTypes($request->get('column_types'));
        $preset->options = CellStylePreset::filterOptions($request->all());
        $preset->order = 0;
        $preset->save();

        return getAjaxResponse([
            'result' => true,
            'toastr' => exmtrans('common.message.success_execute'),
            'preset' => $preset->definition(),
        ]);
    }

    /**
     * Rewrite an existing preset.
     *
     * Every column and view pointing at it repaints, which is the whole point
     * of the button - and the reason only its owner may press it.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function update(Request $request, $tableKey, $suuid)
    {
        $preset = static::findOwned($suuid);
        if (!isset($preset)) {
            return abortJson(403);
        }

        $name = static::readName($request);
        if (is_nullorempty($name)) {
            return abortJson(400, exmtrans('cell_style_preset.error.name_required'));
        }

        $preset->preset_name = $name;
        $preset->column_types = CellStylePreset::filterColumnTypes($request->get('column_types'));
        $preset->options = CellStylePreset::filterOptions($request->all());
        $preset->save();

        return getAjaxResponse([
            'result' => true,
            'toastr' => exmtrans('common.message.success_execute'),
            'preset' => $preset->definition(),
        ]);
    }

    /**
     * Remove a preset.
     *
     * The columns pointing at it are left alone on purpose: they fall back to
     * rendering unstyled, and re-picking a preset is one click. Hunting down
     * every column to rewrite would be a long write in a request that the
     * user thinks of as deleting one row.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function destroy(Request $request, $tableKey, $suuid)
    {
        $preset = static::findOwned($suuid);
        if (!isset($preset)) {
            return abortJson(403);
        }

        $preset->delete();

        return getAjaxResponse([
            'result' => true,
            'toastr' => exmtrans('common.message.success_execute'),
            'deleted' => $suuid,
        ]);
    }

    /**
     * The preset behind this suuid, but only if this user may change it.
     *
     * @param mixed $suuid
     * @return CellStylePreset|null
     */
    protected static function findOwned($suuid): ?CellStylePreset
    {
        if (!is_string($suuid) || is_nullorempty($suuid)) {
            return null;
        }

        /** @var CellStylePreset|null $preset */
        $preset = CellStylePreset::where('suuid', $suuid)->first();
        if (!isset($preset) || !$preset->isEditableBy()) {
            return null;
        }

        return $preset;
    }

    /**
     * @return string|null
     */
    protected static function readName(Request $request): ?string
    {
        $name = $request->get('preset_name');
        if (!is_string($name)) {
            return null;
        }

        $name = mbTrim($name);

        return is_nullorempty($name) ? null : mb_substr($name, 0, 40);
    }
}
