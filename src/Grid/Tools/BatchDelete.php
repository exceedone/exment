<?php

namespace Exceedone\Exment\Grid\Tools;

use Encore\Admin\Grid\Tools\BatchDelete as BatchDeleteBase;

class BatchDelete extends BatchDeleteBase
{
    /**
     * Script of batch delete action.
     */
    public function script()
    {
        $url = url($this->resource);
        $trans = [
            'delete_confirm' => trans('admin.delete_confirm'),
            'confirm'        => trans('admin.confirm'),
            'cancel'         => trans('admin.cancel'),
        ];

        return <<<EOT

$('{$this->getElementClass()}').on('click', function() {

    swal({
        title: "{$trans['delete_confirm']}",
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "#DD6B55",
        confirmButtonText: "{$trans['confirm']}",
        showLoaderOnConfirm: true,
        allowOutsideClick: false,
        cancelButtonText: "{$trans['cancel']}",
        preConfirm: function() {
            $('.swal2-cancel').hide();
            return new Promise(function(resolve) {
                $.ajax({
                    method: 'post',
                    url: '{$url}/' + $.admin.grid.selected().join(),
                    data: {
                        _method:'delete',
                        _token:'{$this->getToken()}'
                    },
                    success: function (repsonse) {
                        Exment.CommonEvent.CallbackExmentAjax(repsonse, resolve);
                    },
                    error: function (repsonse) {
                        Exment.CommonEvent.CallbackExmentAjax(repsonse, resolve);
                    }
                });
            });
        }
    });
});

EOT;
    }
}
