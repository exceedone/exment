<?php

namespace Exceedone\Exment\Services\Notify;

use Illuminate\Support\Collection;
use Exceedone\Exment\Enums\SearchType;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\RelationTable;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\NotifyTarget;

/**
 * RelationColumn. extends column.
 * Now only support email column.
 */
class RelationColumn extends Column
{
    /**
     * CustomColumn
     *
     * @var string|CustomColumn
     */
    protected $column;

    /**
     * RelationTable. Info about relation.
     *
     * @var RelationTable|null
     */
    protected $relationTable;

    public function __construct(Notify $notify, array $action_setting, $column)
    {
        $this->notify = $notify;
        $this->action_setting = $action_setting;
        $this->relationTable = RelationTable::getRelationTableByKey($column);

        $this->column = !is_nullorempty($column) ? CustomColumn::getEloquent(explode('?', $column)[0]) : null;
    }

    public function getModels(?CustomValue $custom_value, ?CustomTable $custom_table): Collection
    {
        $result = collect();
        if (!$this->relationTable || !$this->column) {
            return $result;
        }

        if (SearchType::isSelectTable($this->relationTable->searchType)) {
            // get pivot value
            $pivotValue = $custom_value->getValue($this->relationTable->selectTablePivotColumn);
        } else {
            $pivotValue = $custom_value->getParentValue($this->relationTable->relation);
        }

        if (is_nullorempty($pivotValue)) {
            return $result;
        }

        if (!is_list($pivotValue)) {
            $pivotValue = [$pivotValue];
        }

        foreach ($pivotValue as $value) {
            // Now only support email
            $email = $value->getValue($this->column);
            if (!is_nullorempty($email)) {
                $result->push(NotifyTarget::getModelAsEmail($email));
            }
        }
        return $result;
    }
}
