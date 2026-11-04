<?php

namespace Exceedone\Exment\ColumnItems;

use Exceedone\Exment\Enums\SummaryCondition;
use Exceedone\Exment\Enums\GroupCondition;
use Exceedone\Exment\Model\CustomColumn;

/**
 *
 * @property CustomColumn $custom_column
 */
trait SummaryItemTrait
{
    //for summary  --------------------------------------------------

    /**
     * Get summary condion enum.
     * SUM, COUNT, MIN, MAX
     *
     * @return string|null
     */
    protected function getSummaryCondition()
    {
        $summary_option = array_get($this->options, 'summary_condition');
        $summary_condition = is_null($summary_option) ? null : SummaryCondition::getEnum($summary_option);
        return $summary_condition;
    }
    /**
     * Get summary condion name.
     * SUM, COUNT, MIN, MAX
     *
     * @return string|null
     */
    protected function getSummaryConditionName()
    {
        $summary_condition = $this->getSummaryCondition();
        if (!is_null($summary_condition)) {
            return $summary_condition->lowerKey();
        }
        return null;
    }


    /**
     * Get sqlname for summary
     * Join table: true
     * Wrap: true
     *
     * @return string
     */
    public function getSummaryWrapTableColumn(): string
    {
        $options = $this->getSummaryParams();
        $value_table_column = $options['value_table_column'];
        $group_condition = $options['group_condition'];

        $summary_condition = $this->getSummaryConditionName();
        if ($this->isSummaryAverage()) {
            $result = $this->getSummaryAverageSql("AVG(1.0 * {$this->getSummaryCastColumn()})");
        } elseif (isset($summary_condition)) {
            $result = "$summary_condition({$this->getSummaryCastColumn()})";
        } elseif (isset($group_condition)) {
            /** @phpstan-ignore-next-line */
            $result = \DB::getQueryGrammar()->getDateFormatString($group_condition, $value_table_column, false);
        } else {
            /** @phpstan-ignore-next-line */
            $result = \Exment::wrapColumn($value_table_column);
        }

        return $result;
    }

    /**
     * Columns the sub query of a child relation (1:n, n:n, select_table) selects for this
     * summary, alias => SQL, one row per parent. The main query folds them again:
     * getSummaryJoinResultWrapTableColumn().
     *
     * AVG cannot be folded from per-parent averages (an average of averages weighs a parent
     * with one row as much as one with many), so it selects the sum and the count of the
     * values instead, and the main query divides their totals.
     *
     * @return array<string, string>
     */
    public function getSummarySubQueryColumns(): array
    {
        if (!$this->isSummaryAverage()) {
            return [$this->sqlAsName() => $this->getSummaryWrapTableColumn()];
        }

        $castColumn = $this->getSummaryCastColumn();
        return [
            $this->sqlAsName() => "SUM($castColumn)",
            $this->getSummaryCountAsName() => "COUNT($castColumn)",
        ];
    }

    /**
     * The average of a child relation's values, from the per-parent sums and counts its sub
     * query selected (getSummarySubQueryColumns()). A group without a value is NULL, as AVG
     * gives it.
     * Join table: true
     * Wrap: true
     */
    public function getSummaryAverageJoinResultWrapTableColumn(): string
    {
        $sum = \Exment::wrapColumn($this->getTableColumn($this->sqlAsName()));
        $count = \Exment::wrapColumn($this->getTableColumn($this->getSummaryCountAsName()));
        return $this->getSummaryAverageSql("1.0 * SUM($sum) / NULLIF(SUM($count), 0)");
    }

    /**
     * Whether this column is summarized as AVG.
     */
    protected function isSummaryAverage(): bool
    {
        $summary_condition = $this->getSummaryCondition();
        return isset($summary_condition) && (string) $summary_condition === SummaryCondition::AVG;
    }

    /**
     * Decimal places of an average: the column's own (decimal, currency), else 2 —
     * an integer column has none, but the average of whole numbers is rarely one.
     */
    protected function getSummaryAverageDigits(): int
    {
        $digits = isset($this->custom_column) ? array_get($this->custom_column, 'options.decimal_digit') : null;
        return is_numeric($digits) ? max(0, intval($digits)) : 2;
    }

    /**
     * An average rounded to getSummaryAverageDigits(). "1.0 *" in $average keeps it from
     * being an integer division: SQL Server averages and divides integers as integers.
     */
    protected function getSummaryAverageSql(string $average): string
    {
        return "ROUND($average, {$this->getSummaryAverageDigits()})";
    }

    /**
     * The summarized value cast to the column's type: the column a summary function reads.
     * Join table: true
     * Wrap: true
     */
    protected function getSummaryCastColumn(): string
    {
        $options = $this->getSummaryParams();
        // Already set table, so getCastColumn 3 arg is false.
        return $this->getCastColumn($options['value_table_column'], true, false);
    }

    /**
     * Alias of the count an AVG's sub query selects beside its sum.
     */
    protected function getSummaryCountAsName(): string
    {
        return $this->sqlAsName() . '_count';
    }

    /**
     * Get sqlname for group by
     * Join table: true
     * Wrap: true
     *
     * @param boolean $asSelect if true, get sqlname for select column
     * @param boolean $asSqlAsName if true, get sqlname as name.
     * @return string group by column name
     */
    public function getGroupByWrapTableColumn(bool $asSelect = false, bool $asSqlAsName = false): string
    {
        $options = $this->getSummaryParams();
        $value_table_column = $asSqlAsName ? $this->getTableColumn($this->sqlAsName()) : $options['value_table_column'];
        $group_condition = $options['group_condition'];
        $is_wrapped = $options['is_wrapped'];

        if (isset($group_condition) && !$asSqlAsName) {
            /** @phpstan-ignore-next-line */
            $result = \DB::getQueryGrammar()->getDateFormatString($group_condition, $value_table_column, !$asSelect);
        } else {
            /** @phpstan-ignore-next-line */
            $result = $is_wrapped? $value_table_column: \Exment::wrapColumn($value_table_column);
        }

        return $result;
    }

    // @phpstan-ignore-next-line
    protected function getSummaryParams()
    {
        $group_condition = array_get($this->options, 'group_condition');
        $group_condition = isset($group_condition) ? GroupCondition::getEnum($group_condition) : null;

        // get value_table_column(Contains table and column)
        $value_table_column = $this->getTableColumn($this->sqlname());

        $is_wrapped = false;

        if ($this->isMultipleEnabled()) {
            /** @phpstan-ignore-next-line */
            $value_table_column = \Exment::wrapColumn($value_table_column);
            $value_table_column = "CASE WHEN {$value_table_column} = '[]' THEN NULL ELSE {$value_table_column} END";
            $is_wrapped = true;
        }

        return [
            'group_condition' => $group_condition,
            'value_table_column' => $value_table_column,
            'is_wrapped' => $is_wrapped,
        ];
    }

    /**
     * Get API column name
     *
     * @return string
     */
    protected function _apiName()
    {
        return $this->uniqueName();
    }
}
