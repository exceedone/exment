<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\Model\CustomColumnMulti;
use Exceedone\Exment\Enums\FilterOption;

/**
 * Intefer, decimal, currency common logic
 */
trait NumberTrait
{
    /**
     * whether column is Numeric
     *
     */
    public function isNumeric()
    {
        return true;
    }
    
    /**
     * Compare two values.
     */
    public function compareTwoValues(CustomColumnMulti $compare_column, $this_value, $target_value)
    {
        switch ($compare_column->compare_type) {
            case FilterOption::COMPARE_GT:
                if ($this_value > $target_value) {
                    return true;
                }

                return $compare_column->getCompareErrorMessage('validation.not_gt', $compare_column->compare_column1, $compare_column->compare_column2);
                
            case FilterOption::COMPARE_GTE:
                if ($this_value >= $target_value) {
                    return true;
                }

                return $compare_column->getCompareErrorMessage('validation.not_gte', $compare_column->compare_column1, $compare_column->compare_column2);
                
            case FilterOption::COMPARE_LT:
                if ($this_value < $target_value) {
                    return true;
                }

                return $compare_column->getCompareErrorMessage('validation.not_lt', $compare_column->compare_column1, $compare_column->compare_column2);
                
            case FilterOption::COMPARE_LTE:
                if ($this_value <= $target_value) {
                    return true;
                }

                return $compare_column->getCompareErrorMessage('validation.not_lte', $compare_column->compare_column1, $compare_column->compare_column2);
        }

        return true;
    }
}
