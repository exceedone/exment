<?php

namespace Exceedone\Exment\Grid\Tools;

use Exceedone\Exment\Model\CustomTable;

/**
 * Shared "is the grid showing deleted rows?" test for the grid tools.
 *
 * Every row on screen in the trashed scope is already soft deleted, and
 * none of the tools' write actions can reach one: the webapi resolves a
 * record without `withTrashed()` (see ApiTrait::getCustomValue), so an
 * edit, a copy or a delete aimed at it comes back "not found" and the
 * user is left with a bare error toast.
 *
 * The stock UI already behaves that way - in this scope laravel-admin's
 * row actions drop edit and delete, and DefaultGrid swaps the batch
 * actions for restore and hard delete. The tools have to follow, or they
 * become the one place in the screen still offering an action that
 * cannot succeed.
 */
trait TrashedScopeTrait
{
    /**
     * Is the current request rendering the grid's trashed scope?
     *
     * The scope only exists when the grid registered it, which it does
     * when the table allows viewing deleted rows (DefaultGrid::manageFilter).
     * Without that pairing a stray `?_scope_=trashed` in the URL would
     * disable the tools on a grid that is showing live rows.
     *
     * The filter's other condition - not a modal grid - needs no test
     * here: DefaultGrid::manageMenuToolButton returns before building any
     * of these tools on modal and preview grids.
     *
     * @param CustomTable|null $custom_table
     * @return bool
     */
    protected static function isTrashedScope($custom_table): bool
    {
        if (!isMatchString(request()->get('_scope_'), 'trashed')) {
            return false;
        }

        return $custom_table instanceof CustomTable
            && $custom_table->enableShowTrashed() === true;
    }
}
