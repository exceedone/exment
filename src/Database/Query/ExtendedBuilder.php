<?php

namespace Exceedone\Exment\Database\Query;

use Exceedone\Exment\Database\ExtendedBuilderTrait;
use Illuminate\Database\Query\Builder;

/**
 * @template TModelClass of \Illuminate\Database\Eloquent\Model
 */
class ExtendedBuilder extends Builder
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
