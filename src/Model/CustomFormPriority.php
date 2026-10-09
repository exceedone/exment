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
    protected $appends = ['form_priority_text', 'condition_join', 'condition_reverse'];
    protected $casts = ['options' => 'json'];


    // @phpstan-ignore-next-line
    public function custom_form()
    {
        return $this->belongsTo(CustomForm::class, 'custom_form_id');
    }


    // @phpstan-ignore-next-line
    public function custom_form_priority_conditions()
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
    protected function _isMatchCondition($custom_value)
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

    // @phpstan-ignore-next-line
    public function getFormPriorityTextAttribute()
    {
        if (isset($this->custom_form_priority_conditions)) {
            $list =[];
            foreach ($this->custom_form_priority_conditions as $condition) {
                $list[] = $condition->condition_text;
            }

            // @phpstan-ignore-next-line
            $glue = exmtrans('common.join_'.$this->condition_join??'and');
            $text = implode($glue, $list);
            if (boolval($this->condition_reverse)) {
                $text = exmtrans('common.condition_reverse'). $text;
            }
            return $text;
        }
        return '';
    }


    // @phpstan-ignore-next-line
    public function getConditionJoinAttribute()
    {
        return $this->getOption('condition_join');
    }


    // @phpstan-ignore-next-line
    public function setConditionJoinAttribute($val)
    {
        $this->setOption('condition_join', $val);

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
        $this->setOption('condition_reverse', $val);

        return $this;
    }


    // @phpstan-ignore-next-line
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
