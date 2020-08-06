<?php

namespace Exceedone\Exment\Database\Query\Grammars;

trait GrammarTrait
{
    public function wrapWhereInMultiple(array $columns)
    {
        return array_map(function ($column) {
            return $this->wrap($column);
        }, $columns);
    }

    /**
     * Bind and flatten value results.
     *
     * @return array offset 0: bind string for wherein (?, ?, )
     */
    public function bindValueWhereInMultiple(array $values)
    {
        $count = 0;
        $bindStrings = array_map(function (array $value) use (&$count) {
            $strs = array_map(function ($v) use (&$count) {
                // set "?"
                $count++;
                return '?';
            //$this->wrapValue($v);
            }, $value);
            return "(".implode(", ", $strs).")";
        }, $values);

        // set flatten values for binding
        $binds = [];

        foreach ($values as $value) {
            foreach ($value as $v) {
                $binds[] = $v;
            }
        }

        return [$bindStrings, $binds];
    }
}
