<?php

namespace Exceedone\Exment\Grid\Tools;

use Encore\Admin\Grid\Tools\BatchAction;

class BatchHardDelete extends BatchAction
{
    public function __construct($title)
    {
        $this->title = $title;
    }

    /**
     * Script of batch delete action.
     */
    public function script()
    {
        $url = url($this->resource);
        $trans = [
            'title' => exmtrans('custom_value.hard_delete'),
            'delete_confirm' => exmtrans('custom_value.message.hard_delete'),
            'confirm'        => trans('admin.confirm'),
            'cancel'         => trans('admin.cancel'),
        ];

        return <<<EOT

$('{$this->getElementClass()}').on('click', function() {

    swal({
        title: "{$trans['title']}",
        text: "{$trans['delete_confirm']}",
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
                        _token:'{$this->getToken()}',
                        trashed: 1,
                    },
                    success: function (data) {
                        $.pjax.reload('#pjax-container');

                        resolve(data);
                    }
                });
            });
        }
    }).then(function(result) {
        var data = result.value;
        if (typeof data === 'object') {
            if (data.status) {
                swal(data.message, '', 'success');
            } else {
                swal(data.message, '', 'error');
            }
        }
    });
});

EOT;
    }
}
