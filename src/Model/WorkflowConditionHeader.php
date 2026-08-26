<?php

namespace Exceedone\Exment\Model;

/**
 * @phpstan-consistent-constructor
 * @property mixed $workflow_conditions
 * @property mixed $workflow_action_id
 * @property mixed $enabled_flg
 * @property mixed $status_to
 */
class WorkflowConditionHeader extends ModelBase
{
    use Traits\UseRequestSessionTrait;
    use Traits\ClearCacheTrait;
    use Traits\DatabaseJsonOptionTrait;
    use Traits\TemplateTrait;

    protected $appends = ['condition_join', 'condition_reverse'];
    protected $casts = ['options' => 'json'];


    // @phpstan-ignore-next-line
    public static $templateItems = [
        'excepts' => ['id'],
        'uniqueKeys' => [
            'export' => ['workflow_action_id', 'status_to'],
            'import' => ['workflow_action_id', 'status_to'],
        ],
        'parent' => 'workflow_action_id',
    ];


    /**
     * Convert status_to id -> status_name so the header is portable across installs.
     */
    public static function exportReplaceJson(&$json)
    {
        if (!array_key_exists('status_to', $json) || is_null($json['status_to'])) {
            return;
        }
        $statusTo = $json['status_to'];
        if ($statusTo === Define::WORKFLOW_START_KEYNAME) {
            return;
        }
        if (is_numeric($statusTo)) {
            $ws = WorkflowStatus::getEloquent($statusTo);
            if ($ws) {
                $json['status_to'] = $ws->status_name;
            }
        }
    }


    /**
     * Convert status_to status_name back to id.
     * The importer passes 'parent' = WorkflowAction instance in $options,
     * which is why we resolve via WorkflowAction -> workflow_id.
     */
    public static function importReplaceJson(&$json, $options = [])
    {
        if (!array_key_exists('status_to', $json) || is_null($json['status_to'])) {
            return;
        }
        $statusTo = $json['status_to'];
        if ($statusTo === Define::WORKFLOW_START_KEYNAME) {
            return;
        }
        if (is_numeric($statusTo)) {
            return;
        }

        $workflowAction = array_get($options, 'parent');
        if (!$workflowAction || !isset($workflowAction->workflow_id)) {
            return;
        }
        $ws = WorkflowStatus::where('workflow_id', $workflowAction->workflow_id)
            ->where('status_name', $statusTo)
            ->first();
        if ($ws) {
            $json['status_to'] = (string) $ws->id;
        }
    }


    // @phpstan-ignore-next-line
    public function workflow_action()
    {
        return $this->belongsTo(WorkflowAction::class, 'workflow_action_id');
    }


    // @phpstan-ignore-next-line
    public function workflow_conditions()
    {
        return $this->morphMany(Condition::class, 'morph', 'morph_type', 'morph_id');
    }

    /**
     * check if custom_value and user(organization, role) match for conditions(with reverse option).
     */

    // @phpstan-ignore-next-line
    public function isMatchCondition($custom_value)
    {
        $result = $this->_isMatchCondition($custom_value);
        if (boolval($this->condition_reverse)) {
            $result = !$result;
        }
        return $result;
    }

    /**
     * check if custom_value and user(organization, role) match for conditions.
     */

    // @phpstan-ignore-next-line
    public function _isMatchCondition($custom_value)
    {
        $is_or = $this->condition_join == 'or';
        foreach ($this->workflow_conditions as $condition) {
            if ($is_or) {
                if ($condition->isMatchCondition($custom_value)) {
                    return true;
                }
            } else {
                if (!$condition->isMatchCondition($custom_value)) {
                    return false;
                }
            }
        }
        return !$is_or;
    }

    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($model) {
            $model->deletingChildren();
        });
    }


    // @phpstan-ignore-next-line
    public function deletingChildren()
    {
        $keys = ['workflow_conditions'];
        $this->load($keys);
        foreach ($keys as $key) {
            $this->{$key}()->delete();
        }
    }


    // @phpstan-ignore-next-line
    public function getConditionJoinAttribute()
    {
        return $this->getOption('condition_join');
    }


    // @phpstan-ignore-next-line
    public function setConditionJoinAttribute($val)
    {
        if (is_null($val)) {
            $this->forgetJson('options', 'condition_join');
        } else {
            $this->setOption('condition_join', $val);
        }

        return $this;
    }


    // @phpstan-ignore-next-line
    public function getConditionReverseAttribute()
    {
        return $this->getOption('condition_reverse');
    }


    // @phpstan-ignore-next-line
    public function setConditionReverseAttribute($val)
    {
        if (is_null($val)) {
            $this->forgetJson('options', 'condition_reverse');
        } else {
            $this->setOption('condition_reverse', $val);
        }

        return $this;
    }
}
