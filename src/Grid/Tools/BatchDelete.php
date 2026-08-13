<?php

namespace Exceedone\Exment\Grid\Tools;

use ExmentAdminCore\Admin\Grid\Tools\BatchDelete as BatchDeleteBase;

class BatchDelete extends BatchDeleteBase
{
    /**
     * Prefix the standard batch delete label with a trash icon. The
     * icon shows up both in the stock dropdown menu (`{!! $action->render() !!}`)
     * and in the copy grid_tools.js hangs in the floating selection bar,
     * so the two entries stay recognisable at a glance without wiring
     * two icon lookups.
     *
     * @return string
     */
    public function render()
    {
        return '<i class="fa fa-trash"></i>&nbsp;' . e((string)$this->getTitle());
    }

    /**
     * Script of batch delete action.
     *
     * @return string
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
    event.preventDefault(); 

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
