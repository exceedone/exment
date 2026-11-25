<?php

namespace Exceedone\Exment\Validator;

use Illuminate\Contracts\Validation\Rule;

/**
 * The WIP limit of a column of a kanban: a count of cards is at most 999, a
 * total of a number column ("Count WIP by (number)") has no upper limit (#2184).
 */
class KanbanWipLimitRule implements Rule
{
    public const MAX_CARDS = 999;

    /**
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function passes($attribute, $value)
    {
        if (!is_nullorempty(request()->input('kanban_wip_column_id'))) {
            return true;
        }
        if (!is_numeric($value)) {
            return true;
        }
        return floatval($value) <= static::MAX_CARDS;
    }

    /**
     * @return string
     */
    public function message()
    {
        return strval(trans('validation.max.numeric', ['max' => static::MAX_CARDS]));
    }
}
