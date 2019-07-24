<?php

namespace Exceedone\Exment\PartialCrudItems\Providers;

use Exceedone\Exment\PartialCrudItems\ProviderBase;
use Exceedone\Exment\Enums\SystemTableName;
use Encore\Admin\Tree;

/**
 * OrgazanizationTreeItem
 */
class OrgazanizationTreeItem extends ProviderBase
{
    /**
     * set laravel admin grid's content
     */
    public function setGridContent(&$content)
    {
        $html = getModelName(SystemTableName::ORGANIZATION)::tree(function (Tree $tree) {
            $tree->title(exmtrans('organization.organization_tree'));

            $tree->disableCreate();
            $tree->disableSave();
            $tree->disableRefresh();
            $tree->disableNestable();
            $tree->disableAction();
            $tree->disableExpandCollapse();

            $tree->branch(function ($branch) {
                return array_get($branch, 'label');
            });
        });

        $content->row($html);
    }
}
