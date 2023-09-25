<?php

namespace Exceedone\Exment\Model;

/**
 * @property mixed $order
 * @property mixed $custom_form
 * @property mixed $custom_form_priority_conditions
 * @phpstan-consistent-constructor
 */
class CustomFormPriority extends ModelBase
{
    use Traits\ClearCacheTrait;
    use Traits\DatabaseJsonOptionTrait;

    protected $guarded = ['id'];
    protected $appends = ['form_priority_text', 'condition_join'];
    protected $casts = ['options' => 'json'];

    public function custom_form()
    {
        return $this->belongsTo(CustomForm::class, 'custom_form_id');
    }

    public function custom_form_priority_conditions()
    {
        return $this->morphMany(Condition::class, 'morph', 'morph_type', 'morph_id');
    }

    /**
     * check if custom_value and user(organization, role) match for conditions.
     */
    public function isMatchCondition($custom_value)
    {
        $is_or = $this->condition_join == 'or';
        foreach ($this->custom_form_priority_conditions as $condition) {
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

    /**
     * get filter condition text for grid.
     */
    public function getFormPriorityTextAttribute()
    {
        if (isset($this->custom_form_priority_conditions)) {
            $list =[];
            foreach ($this->custom_form_priority_conditions as $condition) {
                $list[] = $condition->condition_text;
            }
            $glue = exmtrans('common.join_'.$this->condition_join??'and');
            return implode($glue, $list);
        }
        return '';
    }

    public function getConditionJoinAttribute()
    {
        return $this->getOption('condition_join');
    }

    public function setConditionJoinAttribute($val)
    {
        $this->setOption('condition_join', $val);

        return $this;
    }

    public function deletingChildren()
    {
        $this->custom_form_priority_conditions()->delete();
    }

    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($model) {
            $model->deletingChildren();
        });
    }
}
