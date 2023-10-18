<?php

namespace Exceedone\Exment\Grid\Tools;

use Encore\Admin\Grid\Tools\BatchAction;

class BatchShare extends BatchAction
{
    /**
     * Script of batch delete action.
     */
    public function script()
    {
        $url = url($this->resource);
        $uuid = make_uuid();

        return <<<EOT

        $('{$this->getElementClass()}').on('click', function() {
            var url = '{$url}/bulk/shareClick';
            Exment.ModalEvent.ShowModal($("#modal-form-$uuid"), url, {
                'ids': $.admin.grid.selected().join(),
            });
            return;
        });
EOT;
    }
}
