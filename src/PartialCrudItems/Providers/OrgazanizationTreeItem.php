<?php

namespace Exceedone\Exment\PartialCrudItems\Providers;

use Exceedone\Exment\PartialCrudItems\ProviderBase;
use Exceedone\Exment\Enums\SystemTableName;
use Encore\Admin\Tree;

/**
 * OrgazanizationTreeItem
 * @phpstan-consistent-constructor
 */
class OrgazanizationTreeItem extends ProviderBase
{
    /**
     * set laravel admin grid's content
     */
    public function setGridContent(&$content)
    {
        if (!boolval(config('exment.show_organization_tree', false))) {
            return;
        }

        $html = getModelName(SystemTableName::ORGANIZATION)::tree(function (Tree $tree) {
            $tree->title(exmtrans('organization.organization_tree'));

            $tree->disableCreate();
            $tree->disableSave();
            $tree->disableRefresh();
            $tree->disableNestable();
            $tree->disableAction();
            $tree->disableExpandCollapse();

            $tree->getCallback(function ($items) {
                foreach ($items as $item) {
                    $item->append('label');
                }
                return $items;
            });

            $tree->branch(function ($branch) {
                return array_get($branch, 'label');
            });
        });

        $content->row($html);
    }
}
