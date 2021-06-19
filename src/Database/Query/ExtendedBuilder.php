<?php

namespace Exceedone\Exment\Database\Query;

use Exceedone\Exment\Database\ExtendedBuilderTrait;

class ExtendedBuilder extends \Illuminate\Database\Query\Builder
{
    use ExtendedBuilderTrait;
    
    /**
     * Get a new join clause.
     *
     * @param  \Illuminate\Database\Query\Builder  $parentQuery
     * @param  string  $type
     * @param  string  $table
     * @return \Exceedone\Exment\Database\Query\JoinClause
     */
    protected function newJoinClause(\Illuminate\Database\Query\Builder $parentQuery, $type, $table)
    {
        return new JoinClause($parentQuery, $type, $table);
    }
}
