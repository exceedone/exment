<?php

namespace Exceedone\Exment\Grid\Tools;

use Encore\Admin\Grid\Tools\BatchAction;

class BatchRestore extends BatchAction
{
    /**
     * Create a new Tools instance.
     */
    public function __construct()
    {
    }

    /**
     * Script of batch delete action.
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
