<?php

namespace Exceedone\Exment\Middleware;

use Illuminate\Http\Request;
use Encore\Admin\Facades\Admin as Ad;
use Exceedone\Exment\Controllers;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Enums\PluginType;

/**
 * Middleware as Bootstrap.
 * Setup for display. ex. set css, js, ...
 */
class Bootstrap
{
    public function handle(Request $request, \Closure $next)
    {
        $this->setCssJs($request, $next);

        return $next($request);
    }

    /**
     * Set css and js. only first request(not ajax and pjax)
     *
     * @param Request $request
     * @param \Closure $next
     * @return void
     */
    protected function setCssJs(Request $request, \Closure $next)
    {
        if ($request->ajax() || $request->pjax()) {
            return;
        }

        if ($this->isStaticRequest($request)) {
            return;
        }

        Ad::navbar(function (\Encore\Admin\Widgets\Navbar $navbar) {
            $navbar->left(Controllers\SearchController::renderSearchHeader());
            $navbar->left(new \Exceedone\Exment\Form\Navbar\Hidden);
            $navbar->right(new \Exceedone\Exment\Form\Navbar\HelpNav);
            $navbar->right(new \Exceedone\Exment\Form\Navbar\NotifyNav);
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
        Ad::css(asset('vendor/exment/css/workflow.css?ver='.$ver));
        
        Ad::js(asset('vendor/exment/jquery/jquery.color.min.js'));
        Ad::js(asset('vendor/exment/mathjs/math.min.js'));
        Ad::js(asset('vendor/exment/js/numberformat.js?ver='.$ver));
        Ad::js(asset('vendor/exment/fullcalendar/core/main.min.js?ver='.$ver));
        Ad::js(asset('vendor/exment/fullcalendar/core/locales-all.min.js?ver='.$ver));
        Ad::js(asset('vendor/exment/fullcalendar/interaction/main.min.js?ver='.$ver));
        Ad::js(asset('vendor/exment/fullcalendar/daygrid/main.min.js?ver='.$ver));
        Ad::js(asset('vendor/exment/fullcalendar/list/main.min.js?ver='.$ver));
        Ad::js(asset('vendor/exment/fullcalendar/timegrid/main.min.js?ver='.$ver));
        Ad::js(asset('vendor/exment/js/common_all.js?ver='.$ver));
        Ad::js(asset('vendor/exment/js/common.js?ver='.$ver));
        Ad::js(asset('vendor/exment/js/notify_navbar.js?ver='.$ver));
        Ad::js(asset('vendor/exment/js/modal.js?ver='.$ver));
        Ad::js(asset('vendor/exment/js/workflow.js?ver='.$ver));
        Ad::js(asset('vendor/exment/js/changefield.js?ver='.$ver));

        // set scripts
        $pluginPublics = Plugin::getPluginPublics();
        foreach ($pluginPublics as $pluginPublic) {
            // get scripts
            $plugin = $pluginPublic->_plugin();
            $p = $plugin->matchPluginType(PluginType::SCRIPT) ? 'js' : 'css';
            $cdns = array_get($plugin, 'options.cdns', []);
            foreach ($cdns as $cdn) {
                Ad::{$p.'last'}($cdn);
            }

            // get each scripts
            $items = collect($pluginPublic->{$p}(true))->map(function ($item) use ($pluginPublic) {
                return admin_urls($pluginPublic->_plugin()->getRouteUri(), 'public/', $item);
            });
            if (!empty($items)) {
                foreach ($items as $item) {
                    Ad::{$p.'last'}($item);
                }
            }
        }

        // set Plugin resource
        $pluginPages = Plugin::getPluginPages();
        foreach ($pluginPages as $pluginPage) {
            // get css and js
            $publics = ['css', 'js'];
            foreach ($publics as $p) {
                $items = collect($pluginPage->{$p}())->map(function ($item) use ($pluginPage) {
                    return admin_urls($pluginPage->_plugin()->getRouteUri(), 'public/', $item);
                });
                if (!empty($items)) {
                    foreach ($items as $item) {
                        Ad::{$p.'last'}($item);
                    }
                }
            }
        }

        Ad::jslast(asset('vendor/exment/js/customscript.js?ver='.$ver));

        // delete object
        $delete_confirm = trans('admin.delete_confirm');
        $confirm = trans('admin.confirm');
        $cancel = trans('admin.cancel');
        
        $script = <<<EOT
    ///// delete click event
    $(document).off('click', '[data-exment-delete]').on('click', '[data-exment-delete]', {}, function(ev){
        ev.preventDefault();

        // get url
        let url = $(ev.target).closest('[data-exment-delete]').data('exment-delete');
        
        Exment.CommonEvent.ShowSwal(url, {
            title: "$delete_confirm",
            confirm:"$confirm",
            method: 'delete',
            cancel:"$cancel",
        });
    });

EOT;
        Ad::script($script);
    }

    protected function isStaticRequest($request)
    {
        $pathInfo = $request->getPathInfo();
        $extension = strtolower(pathinfo($pathInfo, PATHINFO_EXTENSION));
        return in_array($extension, ['js', 'css', 'png', 'jpg', 'jpeg', 'gif']);
    }
}
