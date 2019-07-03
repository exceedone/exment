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

        Ad::css(asset('vendor/exment/fullcalendar/core/main.min.css?ver='.$ver));
        Ad::css(asset('vendor/exment/fullcalendar/daygrid/main.min.css?ver='.$ver));
        Ad::css(asset('vendor/exment/fullcalendar/list/main.min.css?ver='.$ver));
        Ad::css(asset('vendor/exment/fullcalendar/timegrid/main.min.css?ver='.$ver));
        Ad::css(asset('vendor/exment/css/common.css?ver='.$ver));
        
        Ad::js(asset('vendor/exment/chartjs/chart.min.js'));
        Ad::js(asset('vendor/exment/js/numberformat.js?ver='.$ver));
        Ad::js(asset('vendor/exment/fullcalendar/core/main.min.js?ver='.$ver));
        Ad::js(asset('vendor/exment/fullcalendar/core/locales-all.min.js?ver='.$ver));
        Ad::js(asset('vendor/exment/fullcalendar/interaction/main.min.js?ver='.$ver));
        Ad::js(asset('vendor/exment/fullcalendar/daygrid/main.min.js?ver='.$ver));
        Ad::js(asset('vendor/exment/fullcalendar/list/main.min.js?ver='.$ver));
        Ad::js(asset('vendor/exment/fullcalendar/timegrid/main.min.js?ver='.$ver));
        Ad::js(asset('vendor/exment/js/common.js?ver='.$ver));

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
        
    ///// delete click event
    $(document).off('click', '[data-exment-delete]').on('click', '[data-exment-delete]', {}, function(ev){
        // get url
        var url = $(ev.target).closest('[data-exment-delete]').data('exment-delete');
        
        Exment.CommonEvent.ShowSwal(url, {
            title: "$delete_confirm",
            confirm:"$confirm",
            method: 'delete',
            cancel:"$cancel",
        });
    });
    
    $(document).off('click', '[data-help-text]').on('click', '[data-help-text]', {}, function(ev){
        var elem = $(ev.target).closest('[data-help-text]');
        swal(
            elem.data('help-title'),
            elem.data('help-text'),
            'info'
        );
    });

EOT;
        Ad::script($script);

        return $next($request);
    }
}
