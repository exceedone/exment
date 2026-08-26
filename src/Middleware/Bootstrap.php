<?php

namespace Exceedone\Exment\Middleware;

use Illuminate\Http\Request;
use ExmentAdminCore\Admin\Facades\Admin as Ad;
use Exceedone\Exment\Controllers;
use Exceedone\Exment\Model\Plugin;

/**
 * Middleware as Bootstrap.
 * Setup for display. ex. set css, js, ...
 */
class Bootstrap
{
    use BootstrapTrait;

    /**
     * @param \Closure(Request): mixed $next
     * @return mixed
     */
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

        Ad::navbar(function (\ExmentAdminCore\Admin\Widgets\Navbar $navbar) {
            $navbar->left(Controllers\SearchController::renderSearchHeader());
            $navbar->left(new \Exceedone\Exment\Form\Navbar\Hidden());
            $navbar->right(new \Exceedone\Exment\Form\Navbar\HelpNav());
            $navbar->right(new \Exceedone\Exment\Form\Navbar\NotifyNav());
        });
        Ad::js(asset('lib/js/jquery-ui.min.js'));
        Ad::css(asset('lib/css/jquery-ui.min.css'));

        Ad::js(asset('lib/js/bignumber.min.js'));

        static::setCssJsList([
            // iCheck is not decoration here. Grid\Displayers\RowSelector
            // emits `$('.grid-row-checkbox').iCheck(...).on('ifChanged',
            // ...)`, so without the plugin that call throws and the whole
            // handler chain behind it - `$.admin.grid.select()`, the batch
            // action button, the "N selected" counter - is never bound.
            // The skin has to come with it: iCheck hides the real input
            // and draws its own box, so the JS alone would leave every
            // checkbox invisible.
            'vendor/open-admin/AdminLTE/plugins/iCheck/minimal/blue.css',
            'vendor/exment/css/common.css',
            'vendor/exment/css/workflow.css',
            'vendor/exment/css/workflow_designer.css',
            'vendor/exment/css/customform.css',
            'vendor/exment/codemirror/codemirror.css',
            'vendor/exment/jstree/themes/default/style.min.css',
        ], true);

        // The AdminLTE bundles are appended after everything above, so a
        // stylesheet meant to override the theme's table rules has to go
        // through csslast or it silently loses every specificity tie.
        static::setCssJsList([
            'vendor/exment/css/grid_tools.css',
        ], true, true);

        static::setCssJsList([
            // see the note on the stylesheet above
            'vendor/open-admin/AdminLTE/plugins/iCheck/icheck.min.js',
            'vendor/exment/validation/jquery.validate.js',
            'vendor/exment/chartjs/chart.min.js',
            'vendor/exment/codemirror/codemirror.js',
            'vendor/exment/codemirror/mode/htmlmixed/htmlmixed.js',
            'vendor/exment/codemirror/mode/xml/xml.js',
            'vendor/exment/codemirror/mode/javascript/javascript.js',
            'vendor/exment/codemirror/mode/css/css.js',
            'vendor/exment/codemirror/mode/php/php.js',
            'vendor/exment/codemirror/mode/clike/clike.js',
            'vendor/exment/jquery/jquery.color.min.js',
            'vendor/exment/mathjs/math.min.js',
            'vendor/exment/js/numberformat.js',
            'vendor/exment/fullcalendar/index.global.min.js',
            'vendor/exment/fullcalendar/locales-all.global.min.js',
            'vendor/exment/fullcalendar/UltraDate.min.js',
            'vendor/exment/jstree/jstree.min.js',
            'vendor/exment/js/common_all.js',
            'vendor/exment/js/common.js',
            'vendor/exment/js/grid_tools.js',
            'vendor/exment/js/file-required.js',
            'vendor/exment/js/scroll-restore.js',
            'vendor/exment/js/file-required.js',
            'vendor/exment/js/search.js',
            'vendor/exment/js/calc.js',
            'vendor/exment/js/notify_navbar.js',
            'vendor/exment/js/modal.js',
            'vendor/exment/js/workflow.js',
            'vendor/exment/js/workflow_designer.js',
            'vendor/exment/js/changefield.js',
            'vendor/exment/js/customcolumn.js',
            'vendor/exment/js/customformitem.js',
            'vendor/exment/js/customform.js',
            'vendor/exment/js/hasmanytable-validation.js',
            'vendor/exment/js/preview.js',
            'vendor/exment/js/assign_me.js',
            'vendor/exment/js/paste_attach.js',
            'vendor/exment/js/quickadd.js',
            'vendor/exment/js/mention.js',
            'vendor/exment/js/webapi.js',
            'vendor/exment/js/admin.webapi.js',
            'vendor/exment/js/getbox.js',
            'vendor/exment/js/admin.getbox.js',
            'vendor/exment/js/zxing.js',
        ], false);

        // set scripts
        $pluginPublics = Plugin::getPluginScriptStyles();
        foreach ($pluginPublics as $pluginPublic) {
            static::appendStyleScript($pluginPublic);
        }

        // get exment version
        $ver = \Exment::getExmentCurrentVersion();
        if (!isset($ver)) {
            $ver = date('YmdHis');
        }
        // @phpstan-ignore-next-line
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
}
