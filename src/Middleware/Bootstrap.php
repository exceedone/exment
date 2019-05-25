<?php

namespace Exceedone\Exment\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Encore\Admin;
use Encore\Admin\Facades\Admin as Ad;
use Exceedone\Exment\Controllers;

/**
 * Middleware as Bootstrap.
 * Setup for display. ex. set css, js, ...
 */
class Bootstrap
{
    public function handle(Request $request, \Closure $next)
    {
        Ad::navbar(function (\Encore\Admin\Widgets\Navbar $navbar) {
            $navbar->left(Controllers\SearchController::renderSearchHeader());
            $navbar->right(new \Exceedone\Exment\Form\Tools\HelpNav);
        });
        Ad::js(asset('lib/js/jquery-ui.min.js'));
        Ad::css(asset('lib/css/jquery-ui.min.css'));

        Ad::js(asset('lib/js/bignumber.min.js'));

        // get exment version
        $ver = getExmentCurrentVersion();
        if (!isset($ver)) {
            $ver = date('YmdHis');
        }
        Ad::css(asset('vendor/exment/css/common.css?ver='.$ver));
        Ad::css(asset('vendor/exment/fullcalendar/core/main.min.css?ver='.$ver));
        Ad::css(asset('vendor/exment/fullcalendar/daygrid/main.min.css?ver='.$ver));
        Ad::css(asset('vendor/exment/fullcalendar/list/main.min.css?ver='.$ver));
        Ad::css(asset('vendor/exment/fullcalendar/timegrid/main.min.css?ver='.$ver));
        Ad::js(asset('vendor/exment/js/common.js?ver='.$ver));
        //Ad::js(asset('vendor/exment/js/common.js'));
        Ad::js(asset('vendor/exment/chartjs/chart.min.js'));
        Ad::js(asset('vendor/exment/js/numberformat.js?ver='.$ver));
        Ad::js(asset('vendor/exment/fullcalendar/core/main.min.js?ver='.$ver));
        Ad::js(asset('vendor/exment/fullcalendar/core/locales-all.min.js?ver='.$ver));
        Ad::js(asset('vendor/exment/fullcalendar/interaction/main.min.js?ver='.$ver));
        Ad::js(asset('vendor/exment/fullcalendar/daygrid/main.min.js?ver='.$ver));
        Ad::js(asset('vendor/exment/fullcalendar/list/main.min.js?ver='.$ver));
        Ad::js(asset('vendor/exment/fullcalendar/timegrid/main.min.js?ver='.$ver));
        
        // add admin_url and file delete confirm
        $delete_confirm = trans('admin.delete_confirm');
        $prefix = config('admin.route.prefix') ?? '';
        $base_uri = trim(app('request')->getBaseUrl(), '/') ?? '';
        $admin_url = admin_url();

        // delete object
        $delete_confirm = trans('admin.delete_confirm');
        $confirm = trans('admin.confirm');
        $cancel = trans('admin.cancel');
        
        $script = <<<EOT
        $('body').append($('<input/>', {
            'type':'hidden',
            'id': 'admin_prefix',
            'value': '$prefix'
        }));
        $('body').append($('<input/>', {
            'type':'hidden',
            'id': 'admin_base_uri',
            'value': '$base_uri'
        }));
        $('body').append($('<input/>', {
            'type':'hidden',
            'id': 'admin_uri',
            'value': '$admin_url'
        }));
$("input[type='file']").on("filepredelete", function(jqXHR) {
    var abort = true;
    if (confirm("$delete_confirm")) {
        abort = false;
    }
    return abort; // you can also send any data/object that you can receive on `filecustomerror` event
});

    ///// delete click event
    $(document).off('click', '[data-exment-delete]').on('click', '[data-exment-delete]', {}, function(ev){
        // get url
        var url = $(ev.target).closest('[data-exment-delete]').data('exment-delete');
        swal({
            title: "$delete_confirm",
            type: "warning",
            showCancelButton: true,
            confirmButtonColor: "#DD6B55",
            confirmButtonText: "$confirm",
            allowOutsideClick: false,
            closeOnConfirm: false,
            cancelButtonText: "$cancel",
            preConfirm: function() {
                return new Promise(function(resolve) {
                    $.ajax({
                        method: 'post',
                        url: url,
                        data: {
                            _method:'delete',
                            _token:LA.token,
                            webresponse: true,  
                        },
                        success: function (data) {
                            $.pjax.reload('#pjax-container');
            
                            if (typeof data === 'object') {
                                if (data.status === true || data.result === true) {
                                    swal(data.message, '', 'success');
                                } else {
                                    swal(data.message, '', 'error');
                                }
                            }
                        }
                    });
                });
            }
        });
    });
    

EOT;
        Ad::script($script);
            
        return $next($request);
    }
}
