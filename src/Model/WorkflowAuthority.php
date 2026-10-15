<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\ConditionTypeDetail;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\TemplateImportResult;
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
    use Traits\TemplateColumnRefTrait;

    // workflow_authorities has neither timestamps nor created/updated user
    // columns. Exment itself writes it with a query builder insert; the template
    // importer saves through Eloquent, which would otherwise add them.
    public $timestamps = false;
    public $saving_users = false;


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
     * Make the authority portable.
     *
     * user, organization, column and login_user_column store an id that is
     * assigned per install. They travel as a code (user_code /
     * organization_code) or as "table_name.column_name" instead; the raw id is
     * never carried, because on another system it names a different person or
     * column. system stores a fixed WorkflowTargetSystem value and travels as is.
     *
     * @param array<string, mixed> $json
     * @return void
     */
    public static function exportReplaceJson(&$json)
    {
        $type = strval(array_get($json, 'related_type'));
        if (!static::isTemplateRefType($type)) {
            return;
        }

        $id = array_get($json, 'related_id');
        unset($json['related_id']);

        $ref = static::isTemplateColumnType($type)
            ? static::templateColumnIdToRef($id)
            : static::templateUserOrgIdToCode($type, $id);
        if (isset($ref)) {
            $json['related_ref'] = $ref;
        }
    }

    /**
     * Resolve the portable reference back to an id on this installation.
     *
     * An authority whose user, organization or column is missing here is
     * skipped: keeping a raw id from the source system could grant the action
     * to an unrelated person.
     *
     * @param array<string, mixed> $json
     * @param array<string, mixed> $options
     * @return string|void
     */
    public static function importReplaceJson(&$json, $options = [])
    {
        $type = strval(array_get($json, 'related_type'));
        if (!static::isTemplateRefType($type)) {
            unset($json['related_ref']);
            return;
        }

        $ref = array_get($json, 'related_ref');
        unset($json['related_ref'], $json['related_id']);

        $id = static::isTemplateColumnType($type)
            ? static::templateColumnRefToId($ref)
            : static::templateUserOrgCodeToId($type, $ref);
        if (is_null($id)) {
            \Log::warning('Exment template import: workflow authority skipped, ' . $type . ' "' . strval($ref) . '" does not exist on this system.');
            return TemplateImportResult::CONITNUE;
        }

        $json['related_id'] = strval($id);
    }

    /**
     * Whether the related_id of this type is an id assigned per install.
     */
    protected static function isTemplateRefType(string $type): bool
    {
        return static::isTemplateColumnType($type) || !is_null(static::templateCodeColumn($type));
    }

    protected static function isTemplateColumnType(string $type): bool
    {
        return in_array($type, [
            ConditionTypeDetail::COLUMN()->lowerKey(),
            ConditionTypeDetail::LOGIN_USER_COLUMN()->lowerKey(),
        ], true);
    }

    /**
     * System table and code column identifying a user or an organization.
     *
     * @return array{0: string, 1: string}|null
     */
    protected static function templateCodeColumn(string $type): ?array
    {
        if ($type === ConditionTypeDetail::USER()->lowerKey()) {
            return [SystemTableName::USER, 'user_code'];
        }
        if ($type === ConditionTypeDetail::ORGANIZATION()->lowerKey()) {
            return [SystemTableName::ORGANIZATION, 'organization_code'];
        }
        return null;
    }

    /**
     * @param mixed $id related_id as stored (numeric string or int)
     */
    protected static function templateUserOrgIdToCode(string $type, $id): ?string
    {
        $target = static::templateCodeColumn($type);
        if (is_null($target) || !is_numeric($id)) {
            return null;
        }

        $value = getModelName($target[0])::find($id);
        $code = isset($value) ? $value->getValue($target[1]) : null;

        return is_nullorempty($code) ? null : strval($code);
    }

    /**
     * @param mixed $code related_ref read from the template
     */
    protected static function templateUserOrgCodeToId(string $type, $code): ?int
    {
        $target = static::templateCodeColumn($type);
        if (is_null($target) || !is_scalar($code) || strval($code) === '') {
            return null;
        }

        $value = getModelName($target[0])::where('value->' . $target[1], strval($code))->first();

        return isset($value) ? intval($value->id) : null;
    }
}
