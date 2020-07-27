<?php

namespace Exceedone\Exment\Grid\Tools;

use Encore\Admin\Grid\Tools\BatchAction;

class BatchUpdate extends BatchAction
{
    protected $operation;

    /**
     * Create a new Tools instance.
     *
     * @param Grid $grid
     */
    public function __construct($operation)
    {
        $this->operation = $operation;
    }

    /**
     * Script of batch delete action.
     */
    public function script()
    {
        $url = url($this->resource);
        $suuid = $this->operation->suuid;

        $confirm = trans('admin.confirm');
        $cancel = trans('admin.cancel');

        $label = $this->operation->getOption('button_label') ?? $this->operation->operation_name;
        $text = exmtrans('common.message.confirm_execute', exmtrans('custom_operation.custom_operation'));

        return <<<EOT

$('{$this->getElementClass()}').on('click', function() {
    var url = '{$url}/operationClick';
    Exment.CommonEvent.ShowSwal(url, {
        title: "$label",
        confirm:"$confirm",
        cancel:"$cancel",
        text:"$text",
        data: {
            _method:'post',
            _token:'{$this->getToken()}',
            suuid: '$suuid',
            id: $.admin.grid.selected().join(),
        },
    });
});

EOT;
    }
}
