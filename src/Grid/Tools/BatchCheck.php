<?php

namespace Exceedone\Exment\Grid\Tools;

use Encore\Admin\Grid\Tools\BatchAction;

class BatchCheck extends BatchAction
{
    /**
     * Script of batch delete action.
     */
    public function script()
    {
        $url = url($this->resource);

        return <<<EOT

$('{$this->getElementClass()}').on('click', function() {
    $.ajax({
        method: 'post',
        url: '{$url}/rowcheck/' + $.admin.grid.selected().join(),
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
