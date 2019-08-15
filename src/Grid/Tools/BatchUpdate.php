<?php

namespace Exceedone\Exment\Grid\Tools;

use Encore\Admin\Grid\Tools\BatchAction;

class BatchUpdate extends BatchAction
{
    protected $operation_id;

    /**
     * Create a new Tools instance.
     *
     * @param Grid $grid
     */
    public function __construct($operation_id)
    {
        $this->operation_id = $operation_id;
    }

    /**
     * Script of batch delete action.
     */
    public function script()
    {
        $url = url($this->resource);
        $operation_id = $this->operation_id;

        return <<<EOT

$('{$this->getElementClass()}').on('click', function() {
    $.ajax({
        method: 'post',
        url: '{$url}/{$operation_id}/rowUpdate/' + $.admin.grid.selected().join(),
        data: {
            _method:'post',
            _token:'{$this->getToken()}'
        },
    })
    .then(
        function (repsonse) {
            $.pjax.reload('#pjax-container');
            Exment.CommonEvent.CallbackExmentAjax(repsonse);
        },
        function (repsonse) {
            Exment.CommonEvent.CallbackExmentAjax(repsonse);
        }
    );
});

EOT;
    }
}
