<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\ConditionTypeDetail;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\WorkflowTargetSystem;
use Exceedone\Exment\Model\Interfaces\WorkflowAuthorityInterface;
use Exceedone\Exment\ConditionItems\ConditionItemBase;

/**
 * @phpstan-consistent-constructor
 * @property mixed $related_id
 * @property mixed $related_type
 * @method static \Illuminate\Database\Query\Builder insert(array $values)
 */
class WorkflowAuthority extends ModelBase implements WorkflowAuthorityInterface
{
    use Traits\UseRequestSessionTrait;
    use Traits\TemplateTrait;


    // @phpstan-ignore-next-line
    public static $templateItems = [
        'excepts' => ['id'],
        'parent' => 'workflow_action_id',
        'uniqueKeys' => ['workflow_action_id', 'related_type', 'related_id'],
    ];


    // @phpstan-ignore-next-line
    public function getAuthorityTextAttribute()
    {
        $item = ConditionItemBase::getDetailItemByAuthority(null, $this);
        if (is_nullorempty($item)) {
            return null;
        }

        $condition_type = ConditionTypeDetail::getEnum($this->related_type);
        if (!isset($condition_type)) {
            return null;
        }
        $condition_type_label = $condition_type->transKey('condition.condition_type_options');

        return $item->getText($this->related_type, $this->related_id, false);
    }

    /**
     * Get workflow authorities from value array
     *
     * @param string|array $values
     * @param WorkflowAction $values
     * @return array
     */

    // @phpstan-ignore-next-line
    public static function getAuhoritiesFromValue($values, $action = null)
    {
        $values = jsonToArray($values);

        $items = [];
        foreach ($values as $key => $value) {
            foreach ((array)$value as $v) {
                $condition_type = ConditionTypeDetail::getEnum($key);
                if (!isset($condition_type)) {
                    continue;
                }

                $authority = new WorkflowAuthority();
                $authority->related_id = $v;
                $authority->related_type = $key;
                $authority->workflow_action_id = isset($action) ? $action->id : null;

                $items[] = $authority;
            }
        }

        return $items;
    }


    /**
     * Get this workflow action's user, organizaions, and labels
     *
     * @return array
     */

    // @phpstan-ignore-next-line
    public function getWorkflowAuthorityUserOrgLabels(CustomValue $custom_value, WorkflowAction $workflow_action, bool $asNextAction = false): array
    {
        $workflow = $workflow_action->workflow_cache;
        $type = ConditionTypeDetail::getEnum($this->related_type);
        switch ($type) {
            case ConditionTypeDetail::USER:
                return [
                    'users' => [$this->related_id],
                ];
            case ConditionTypeDetail::ORGANIZATION:
                return [
                    'organizations' => [$this->related_id],
                ];
            case ConditionTypeDetail::SYSTEM:
                if ($this->related_id == WorkflowTargetSystem::CREATED_USER) {
                    return [
                        'users' => [$custom_value->created_user_id],
                    ];
                }
                break;
            case ConditionTypeDetail::COLUMN:
                $column = CustomColumn::getEloquent($this->related_id);
                $column_values = $custom_value->getValue($column);
                if (is_nullorempty($column_values)) {
                    return [];
                }
                if ($column_values instanceof CustomValue) {
                    $column_values = [$column_values];
                }

                $userIds = [];
                $organizationIds = [];
                foreach ($column_values as $column_value) {
                    if ($column->column_type == ColumnType::USER) {
                        $userIds[] = $column_value->id;
                    } else {
                        $organizationIds[] = $column_value->id;
                    }
                }

                // Filter user and org by target table
                $custom_table = $custom_value->custom_table;
                $userIds = $custom_table->filterAccessibleUsers($userIds)->toArray();
                $organizationIds = $custom_table->filterAccessibleOrganizations($organizationIds)->toArray();

                return [
                    'users' => $userIds,
                    'organizations' => $organizationIds,
                ];

            case ConditionTypeDetail::LOGIN_USER_COLUMN:
                return \Exceedone\Exment\ConditionItems\LoginUserColumnItem::getTargetUserAndOrg($custom_value, $workflow_action, $this->related_id, $asNextAction);
        }

        return [];
    }

    /**
     * Export replace json - convert related_id to name-based reference for COLUMN type
     *
     * @param array $json
     * @return void
     */
    public static function exportReplaceJson(&$json)
    {
        // For COLUMN type, convert ID to table.column_name format
        if (array_key_exists('related_type', $json) && array_key_exists('related_id', $json)) {
            $relatedType = $json['related_type'];
            $relatedId = $json['related_id'];
            
            if ($relatedType === 'column' && is_numeric($relatedId)) {
                $column = CustomColumn::getEloquent($relatedId);
                if ($column) {
                    $table = $column->custom_table;
                    if ($table) {
                        $json['related_ref'] = $table->table_name . '.' . $column->column_name;
                        unset($json['related_id']);
                    }
                }
            }
            // For other types (user, organization, system), keep as-is (ID-based, only portable within same install)
        }
    }

    /**
     * Import replace json - convert name-based reference back to related_id for COLUMN type
     *
     * @param array $json
     * @param array $options
     * @return void
     */
    public static function importReplaceJson(&$json, $options = [])
    {
        // For COLUMN type with related_ref, convert back to ID
        if (array_key_exists('related_type', $json) && array_key_exists('related_ref', $json)) {
            $relatedType = $json['related_type'];
            $relatedRef = $json['related_ref'];
            
            if ($relatedType === 'column' && strpos($relatedRef, '.') !== false) {
                list($tableName, $columnName) = explode('.', $relatedRef, 2);
                
                $table = CustomTable::getEloquent($tableName);
                if ($table) {
                    $column = $table->custom_columns_cache->first(function ($col) use ($columnName) {
                        return $col->column_name === $columnName;
                    });
                    
                    if ($column) {
                        $json['related_id'] = $column->id;
                        unset($json['related_ref']);
                    }
                }
            }
        }
    }
}
