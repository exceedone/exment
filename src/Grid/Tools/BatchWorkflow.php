<?php

namespace Exceedone\Exment\Grid\Tools;

use ExmentAdminCore\Admin\Grid\Tools\BatchAction;

/**
 * Run a workflow action on the ticked rows of the list (#1648).
 * The modal (CustomValueController::workflowBulkModal()) lists the actions the user can run on them.
 */
class BatchWorkflow extends BatchAction
{
    /**
     * The icon of the workflow buttons of the record page. GridBulkBar copies it to the bar.
     *
     * @return string
     */
    public function render()
    {
        return '<i class="fa fa-check-square"></i>&nbsp;' . e((string)$this->getTitle());
    }

    /**
     * @return string
     */
    public function script()
    {
        $url = url($this->resource);

        return <<<EOT

$('{$this->getElementClass()}').on('click', function(ev) {
    ev.preventDefault();
    Exment.ModalEvent.ShowModal($(this), '{$url}/workflowBulkModal', {
        id: $.admin.grid.selected().join(),
    });
});

EOT;
    }
}
