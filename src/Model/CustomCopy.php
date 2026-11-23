<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Database\Eloquent\ExtendedBuilder;
use Exceedone\Exment\Enums\CopyColumnType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @phpstan-consistent-constructor
 * @property mixed $from_custom_table_id
 * @method static int count($columns = '*')
 * @method static ExtendedBuilder whereIn($column, $values, $boolean = 'and', $not = false)
 * @method static ExtendedBuilder orderBy($column, $direction = 'asc')
 * @method static ExtendedBuilder create(array $attributes = [])
 */
class CustomCopy extends ModelBase implements Interfaces\TemplateImporterInterface
{
    use Traits\UseRequestSessionTrait;
    use Traits\AutoSUuidTrait;
    use Traits\DatabaseJsonOptionTrait;
    use Traits\TemplateTrait;

    protected $casts = ['options' => 'json'];


    // @phpstan-ignore-next-line
    public static $templateItems = [
        'excepts' => ['from_custom_table', 'to_custom_table', 'target_copy_name'],
        'langs' => [
            'keys' => ['suuid'],
            'values' => ['options.label'],
        ],
        'uniqueKeys' => ['suuid'],
        'uniqueKeyReplaces' => [
            [
                'replaceNames' => [
                    [
                        'replacingName' => 'from_custom_table_id',
                        'replacedName' => [
                            'table_name' => 'from_custom_table_name',
                        ]
                    ],
                ],
                'uniqueKeyClassName' => CustomTable::class,
            ],
            [
                'replaceNames' => [
                    [
                        'replacingName' => 'to_custom_table_id',
                        'replacedName' => [
                            'table_name' => 'to_custom_table_name',
                        ]
                    ],
                ],
                'uniqueKeyClassName' => CustomTable::class,
            ],
        ],
        'children' =>[
            'custom_copy_columns' => CustomCopyColumn::class,
            'custom_copy_input_columns' => CustomCopyColumn::class,
        ],
    ];


    /**
     * @return BelongsTo<CustomTable, $this>
     */
    public function from_custom_table(): BelongsTo
    {
        return $this->belongsTo(CustomTable::class, 'from_custom_table_id');
    }


    /**
     * @return BelongsTo<CustomTable, $this>
     */
    public function to_custom_table(): BelongsTo
    {
        return $this->belongsTo(CustomTable::class, 'to_custom_table_id');
    }


    /**
     * @return HasMany<CustomCopyColumn, $this>
     */
    public function custom_copy_columns(): HasMany
    {
        return $this->hasMany(CustomCopyColumn::class, 'custom_copy_id')
        ->where('copy_column_type', CopyColumnType::DEFAULT);
    }


    /**
     * @return HasMany<CustomCopyColumn, $this>
     */
    public function custom_copy_input_columns(): HasMany
    {
        return $this->hasMany(CustomCopyColumn::class, 'custom_copy_id')
        ->where('copy_column_type', CopyColumnType::INPUT);
    }

    /**
     * execute data copy with request parameter
     */

    // @phpstan-ignore-next-line
    public function executeRequest($from_custom_value, $request = null)
    {
        return $this->execute($from_custom_value, $request->all());
    }

    /**
     * execute data copy
     */

    // @phpstan-ignore-next-line
    public function execute($from_custom_value, $inputs = null)
    {
        $to_custom_value = null;
        \ExmentDB::transaction(function () use (&$to_custom_value, $from_custom_value, $inputs) {
            $to_custom_value = static::saveCopyModel(
                $this->custom_copy_columns,
                $this->custom_copy_input_columns,
                $this->to_custom_table,
                $from_custom_value,
                $inputs
            );

            $child_copy_id = $this->getOption('child_copy');
            if (isset($child_copy_id)) {
                /** @var CustomCopy $child_copy */
                $child_copy = static::find($child_copy_id);

                // get from-children values
                $from_child_custom_values = $from_custom_value->getChildrenValues($child_copy->from_custom_table_id) ?? [];

                // loop children values
                foreach ($from_child_custom_values as $from_child_custom_value) {
                    // update parent_id to $to_custom_value->id
                    $from_child_custom_value->parent_id = $to_custom_value->id;
                    $from_child_custom_value->parent_type = $this->to_custom_table->table_name;
                    // execute copy
                    static::saveCopyModel(
                        $child_copy->custom_copy_columns,
                        $child_copy->custom_copy_input_columns,
                        $child_copy->to_custom_table,
                        $from_child_custom_value,
                        null,
                        true
                    );
                }
            }

            return true;
        });

        return [
            'result'  => true,
            'toastr' => sprintf(exmtrans('common.message.success_execute')),
            // set redirect url
            'redirect' => admin_urls('data', $this->to_custom_table->table_name, $to_custom_value->id)
        ];
    }


    /**
     * The copy settings whose button is on the new-record form of this table.
     *
     * Only settings the admin turned on ("use_on_create"), and only those whose
     * source table the user may see, so the button never offers data the user
     * cannot open.
     *
     * @param CustomTable $to_custom_table
     * @return \Illuminate\Database\Eloquent\Collection<int, static>
     */
    public static function getCreateSources(CustomTable $to_custom_table)
    {
        return static::query()->where('to_custom_table_id', $to_custom_table->id)->get()->filter(function ($copy) {
            if (!boolval($copy->getOption('use_on_create'))) {
                return false;
            }
            $from = $copy->from_custom_table;
            return isset($from) && $from->hasPermission(\Exceedone\Exment\Enums\Permission::AVAILABLE_VIEW_CUSTOM_VALUE);
        })->values();
    }

    /**
     * The label of the button on the new-record form.
     *
     * The admin's own label when there is one; otherwise "Copy from", with the
     * copy-from table when the form has more than one such button.
     *
     * @param CustomCopy $copy
     * @param bool $several
     * @return string
     */
    public static function getCreateLabel(CustomCopy $copy, bool $several): string
    {
        $label = $copy->getOption('create_label');
        if (is_string($label) && trim($label) !== '') {
            return $label;
        }
        if ($several) {
            return strval(exmtrans('custom_copy.copy_from.button_table', $copy->from_custom_table->table_view_name));
        }
        return strval(exmtrans('custom_copy.copy_from.button'));
    }

    /**
     * Make a new record of the copy-to table from a record, without saving it.
     *
     * The new-record form starts from this model: the user sees the values,
     * changes what differs, and only then saves.
     *
     * @param CustomValue $from_custom_value
     * @return CustomValue
     */
    public function makeNewModel($from_custom_value)
    {
        return static::makeCopyModel(
            $this->custom_copy_columns,
            $this->to_custom_table,
            $from_custom_value
        );
    }

    // @phpstan-ignore-next-line
    protected static function saveCopyModel(
        $custom_copy_columns,
        $custom_copy_input_columns,
        $to_custom_table,
        $from_custom_value,
        $inputs = null,
        $skipParent = false
    ) {
        $to_custom_value = static::makeCopyModel($custom_copy_columns, $to_custom_table, $from_custom_value, $skipParent);

        // has input parameters, set value from input
        if (isset($inputs)) {
            foreach ($custom_copy_input_columns as $custom_copy_input_column) {
                $custom_column = $custom_copy_input_column->to_custom_column;
                if (is_nullorempty($custom_column)) {
                    continue;
                }
                // get input value
                $val = array_get($inputs, $custom_column->column_name);
                if (isset($val)) {
                    $to_custom_value->setValue($custom_column->column_name, $val);
                }
            }
        }
        // save
        $to_custom_value->saveOrFail();
        return $to_custom_value;
    }

    // @phpstan-ignore-next-line
    protected static function makeCopyModel(
        $custom_copy_columns,
        $to_custom_table,
        $from_custom_value,
        $skipParent = false
    ) {
        // get to_custom_value model
        $to_modelname = getModelName($to_custom_table);
        $to_custom_value = new $to_modelname();

        // set system column
        $to_custom_value->parent_id = $from_custom_value->parent_id;
        $to_custom_value->parent_type = $from_custom_value->parent_type;

        // loop for custom_copy_columns
        foreach ($custom_copy_columns as $custom_copy_column) {
            $fromkey = static::getColumnValueKey(
                $custom_copy_column->from_condition_item,
                $custom_copy_column->from_column_target_id,
                $custom_copy_column->from_custom_column
            );
            $val = array_get($from_custom_value, $fromkey);

            $tokeys = static::getColumnValueKey(
                $custom_copy_column->to_condition_item,
                $custom_copy_column->to_column_target_id,
                $custom_copy_column->to_custom_column
            );

            if ($skipParent && $tokeys == Define::PARENT_ID_NAME) {
                continue;
            }

            $tokeys = explode('.', $tokeys);
            if (count($tokeys) > 1 && $tokeys[0] == 'value') {
                $to_custom_value->setValue($tokeys[1], $val);
            } else {
                $to_custom_value->{$tokeys[0]} = $val;
            }
        }

        return $to_custom_value;
    }


    // @phpstan-ignore-next-line
    protected static function getColumnValueKey($condition_item, $column_type_target, $custom_column)
    {
        return $condition_item ? $condition_item->getColumnValueKey($column_type_target, $custom_column) : null;
    }

    /**
     * get eloquent using request settion.
     * now only support only id.
     */

    // @phpstan-ignore-next-line
    public static function getEloquent($id, $withs = [])
    {
        return static::getEloquentDefault($id, $withs);
    }


    // @phpstan-ignore-next-line
    public function deletingChildren()
    {
        $this->custom_copy_columns()->delete();
        $this->custom_copy_input_columns()->delete();
    }

    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($model) {
            $model->deletingChildren();
        });
    }
}
