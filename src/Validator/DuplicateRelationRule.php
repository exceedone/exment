<?php

namespace Exceedone\Exment\Validator;

use Illuminate\Contracts\Validation\Rule;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Model\CustomRelation;

/**
 * NumberMinRule.
 * Consider comma.
 */
class DuplicateRelationRule implements Rule
{
    protected $relation_id;

    public function __construct($relation_id)
    {
        $this->relation_id = $relation_id;
    }

    /**
    * Check Validation
    *
    * @param  string  $attribute
    * @param  mixed  $value
    * @return bool
    */
    public function passes($attribute, $value)
    {
        $relation_type = request()->input('relation_type');

        if ($relation_type == RelationType::ONE_TO_MANY) {
            $query = CustomRelation::where('child_custom_table_id', $value)
                ->where('relation_type', RelationType::ONE_TO_MANY);
            if (!is_nullorempty($this->relation_id)) {
                $query = $query->where('id', '<>', $this->relation_id);
            }
            return !($query->exists());
        }

        return true;
    }

    /**
     * get validation error message
     *
     * @return string
     */
    public function message()
    {
        return exmtrans('validation.duplicate_relation');
    }
}
