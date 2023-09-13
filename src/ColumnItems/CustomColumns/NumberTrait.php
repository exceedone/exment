<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\Model\CustomColumnMulti;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Services\Calc\CalcService;

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


    /**
     * Set Custom Column Option Form. Using laravel-admin form option
     * https://laravel-admin.org/docs/#/en/model-form-fields
     *
     * @param mixed $form
     * @return void
     */
    public function setCustomColumnOptionFormNumber(&$form)
    {
        $id = request()->route('id');

        $form->number('number_min', exmtrans("custom_column.options.number_min"))
            ->disableUpdown()
            ->defaultEmpty();

        $form->number('number_max', exmtrans("custom_column.options.number_max"))
            ->disableUpdown()
            ->defaultEmpty();

        $form->switchbool('number_format', exmtrans("custom_column.options.number_format"))
            ->help(exmtrans("custom_column.help.number_format"));


        // calc
        $custom_table = $this->custom_table;
        $form->valueModal('calc_formula', exmtrans("custom_column.options.calc_formula"))
            ->help(exmtrans("custom_column.help.calc_formula") . \Exment::getMoreTag('column', 'custom_column.options.calc_formula'))
            ->ajax(admin_urls('column', $custom_table->table_name, $id, 'calcModal'))
            ->modalContentname('options_calc_formula')
            ->nullText(exmtrans('common.no_setting'))
            ->valueTextScript('Exment.CustomColumnEvent.GetSettingValText();')
            ->text(function ($value) use ($custom_table) {
                return CalcService::getCalcDisplayText($value, $custom_table);
            })
        ;

        $form->switchbool('force_caculate', exmtrans("custom_column.options.force_caculate"))
            ->help(exmtrans("custom_column.help.force_caculate"));
    }
}
