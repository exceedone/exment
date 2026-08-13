<?php

namespace Exceedone\Exment\Grid\Tools;

use ExmentAdminCore\Admin\Grid\Tools\BatchAction;

class BatchRestore extends BatchAction
{
    /**
     * Create a new Tools instance.
     */
    public function __construct()
    {
    }

    /**
     * Prefix the restore label with an undo icon so it stands apart
     * from delete / hard delete when the row appears in the copied
     * bulk action bar as well as in the stock dropdown.
     *
     * @return string
     */
    public function render()
    {
        return '<i class="fa fa-undo"></i>&nbsp;' . e((string)$this->getTitle());
    }

    /**
     * Script of batch delete action.
     *
     * @return string
     */
    public function script()
    {
        $url = url($this->resource);

        $confirm = trans('admin.confirm');
        $cancel = trans('admin.cancel');

        $label = exmtrans('custom_value.restore');
        $text = exmtrans('custom_value.message.restore');

        return <<<EOT

$('{$this->getElementClass()}').on('click', function() {
    var url = '{$url}/rowRestore';
    Exment.CommonEvent.ShowSwal(url, {
        title: "$label",
        confirm:"$confirm",
        cancel:"$cancel",
        text:"$text",
        data: {
            _method:'post',
            _token:'{$this->getToken()}',
            id: $.admin.grid.selected().join()
        },
    });
});

EOT;
    }
}
