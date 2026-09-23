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
    use Traits\TemplateTrait;

    protected $guarded = ['id'];
    protected $appends = ['form_priority_text', 'condition_join', 'condition_reverse'];
    protected $casts = ['options' => 'json'];

    /**
     * A form display priority is nothing but its conditions plus the order they
     * are evaluated in, so the conditions have to travel with it.
     *
     * The three appended attributes are all derived: form_priority_text is
     * display text, and condition_join / condition_reverse are read from and
     * written back into options, which is exported on its own. Carrying them
     * would only write their values into options a second time - as null when
     * they were never set.
     */
    // @phpstan-ignore-next-line
    public static $templateItems = [
        'excepts' => ['id', 'form_priority_text', 'condition_join', 'condition_reverse'],
        'uniqueKeys' => ['custom_form_id', 'order'],
        'parent' => 'custom_form_id',
        'children' => [
            'custom_form_priority_conditions' => Condition::class,
        ],
    ];


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

            $glue = exmtrans('common.join_' . ($this->condition_join ?? 'and'));
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
