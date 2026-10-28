<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Exceedone\Exment\Enums\TemplateImportResult;
use Carbon\Carbon;

/**
 * @phpstan-consistent-constructor
 * @property mixed $workflow_id
 * @property mixed $custom_table_id
 * @property mixed $active_flg
 * @property mixed $active_start_date
 * @property mixed $active_end_date
 * @method static int count($columns = '*')
 */
class WorkflowTable extends ModelBase
{
    use Traits\UseRequestSessionTrait;
    use Traits\ClearCacheTrait;
    use Traits\TemplateTrait;


    // @phpstan-ignore-next-line
    public static $templateItems = [
        // The exporter eager loads custom_table to resolve the unique key, and
        // toArray() then carries the whole relation into the template. It is
        // not a column here, so writing it back on import fails.
        'excepts' => ['id', 'custom_table'],
        'uniqueKeys' => [
            'export' => ['custom_table.table_name'],
            'import' => ['custom_table_id'],
        ],
        'parent' => 'workflow_id',
        'uniqueKeyReplaces' => [
            [
                'replaceNames' => [
                    [
                        'replacingName' => 'custom_table_id',
                        'replacedName' => [
                            'table_name' => 'table_name',
                        ]
                    ]
                ],
                'uniqueKeyClassName' => CustomTable::class,
            ],
        ],
    ];


    /**
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class, 'workflow_id');
    }


    /**
     * @return BelongsTo<CustomTable, $this>
     */
    public function custom_table(): BelongsTo
    {
        return $this->belongsTo(CustomTable::class, 'custom_table_id');
    }


    /**
     * Template import guard for the table assignment.
     *
     * - A table missing on this system is skipped instead of leaving a row with
     *   an empty custom_table_id.
     * - The beginning settings screen refuses two active workflows on one table
     *   for overlapping dates, and Workflow::getWorkflowByTable() assumes that.
     *   If the import would break it, the assignment is brought in inactive so
     *   an administrator can activate it through that screen.
     *
     * @param array<string, mixed> $json
     * @param array<string, mixed> $options
     * @return string|void
     */
    public static function importReplaceJson(&$json, $options = [])
    {
        $table_name = array_get($json, 'table_name');
        $custom_table = is_nullorempty($table_name) ? null : CustomTable::where('table_name', $table_name)->first();
        if (!isset($custom_table)) {
            \Log::warning('Exment template import: workflow table skipped, table "' . strval($table_name) . '" does not exist on this system.');
            return TemplateImportResult::CONITNUE;
        }

        if (!boolval(array_get($json, 'active_flg'))) {
            return;
        }

        $range = function ($start, $end) {
            return [
                Carbon::parse(is_nullorempty($start) ? '1900-01-01' : $start),
                Carbon::parse(is_nullorempty($end) ? '9999-12-31' : $end),
            ];
        };
        try {
            [$start, $end] = $range(array_get($json, 'active_start_date'), array_get($json, 'active_end_date'));
            $conflict = static::where('custom_table_id', $custom_table->id)
                ->where('workflow_id', '<>', array_get($options, 'parent.id'))
                ->where('active_flg', true)
                ->get()
                ->contains(function ($other) use ($range, $start, $end) {
                    [$otherStart, $otherEnd] = $range($other->active_start_date, $other->active_end_date);
                    return $start->lte($otherEnd) && $end->gte($otherStart);
                });
        } catch (\Exception $e) {
            // An unreadable date in the template: bring it in inactive as well.
            $conflict = true;
        }
        if (!$conflict) {
            return;
        }

        \Log::warning('Exment template import: workflow imported inactive on table "' . $table_name . '", another workflow is active for an overlapping period or the period is invalid.');
        $json['active_flg'] = 0;
        $json['active_start_date'] = null;
        $json['active_end_date'] = null;
    }
}
