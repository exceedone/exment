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

    protected $appends = ['condition_join', 'condition_reverse'];
    protected $casts = ['options' => 'json'];

    public function workflow_action()
    {
        return $this->belongsTo(WorkflowAction::class, 'workflow_action_id');
    }

    public function workflow_conditions()
    {
        return $this->morphMany(Condition::class, 'morph', 'morph_type', 'morph_id');
    }

    /**
     * check if custom_value and user(organization, role) match for conditions(with reverse option).
     */
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

    public function deletingChildren()
    {
        $keys = ['workflow_conditions'];
        $this->load($keys);
        foreach ($keys as $key) {
            $this->{$key}()->delete();
        }
    }

    public function getConditionJoinAttribute()
    {
        return $this->getOption('condition_join');
    }

    public function setConditionJoinAttribute($val)
    {
        if (is_null($val)) {
            $this->forgetJson('options', 'condition_join');
        } else {
            $this->setOption('condition_join', $val);
        }

        return $this;
    }

    public function getConditionReverseAttribute()
    {
        return $this->getOption('condition_reverse');
    }

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
