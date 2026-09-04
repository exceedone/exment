<?php

namespace Exceedone\Exment\Middleware;

use Encore\Admin\Facades\Admin as Ad;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Enums\PluginType;

trait BootstrapTrait
{
    /**
     * @param array<int, string> $list
     * @param bool $isCss
     * @param bool $isLast
     * @return void
     * @phpstan-ignore-next-line
     */
    protected static function setCssJsList(array $list, bool $isCss, bool $isLast = false)
    {
        /** @phpstan-ignore-next-line */
        $ver = \Exment::getExmentCurrentVersion();
        if (!isset($ver)) {
            $ver = date('YmdHis');
        }

        $func = ($isCss ? 'css' : 'js');
        if ($isLast) {
            $func .= 'last';
        }
        foreach ($list as $l) {
            Ad::{$func}(asset($l . '?ver='.$ver));
        }
    }


    /**
     * @param mixed $request
     * @return bool
     */
    protected function isStaticRequest($request)
    {
        $pathInfo = $request->getPathInfo();
        $extension = strtolower(pathinfo($pathInfo, PATHINFO_EXTENSION));
        return in_array($extension, ['js', 'css', 'png', 'jpg', 'jpeg', 'gif']);
    }

    /**
     * append Style and sript to page
     *
     * @param mixed $pl
     * @param bool $asPublicForm
     * @return void
     * @throws \Exception
     * @phpstan-ignore-next-line
     */
    protected static function appendStyleScript($pl, bool $asPublicForm = false)
    {
        // get each scripts
        foreach (['css', 'js'] as $p) {
            $pluginType = ($p == 'css' ? PluginType::STYLE : PluginType::SCRIPT);
            if ($pl instanceof Plugin) {
                $plugin = $pl;
                $pluginClass = $plugin->getClass($pluginType, [
                    'throw_ex' => false,
                ]);
            } else {
                $pluginClass = $pl;
                $plugin = $pluginClass->_plugin();
            }

            if (!$pluginClass) {
                continue;
            }

            // get scripts
            /** @phpstan-ignore-next-line */
            $cdns = array_get($plugin, 'options.cdns', []);
            foreach ($cdns as $cdn) {
                $ext = pathinfo($cdn, PATHINFO_EXTENSION);
                /** @phpstan-ignore-next-line */
                $p = isMatchString($ext, 'js') ? 'js' : (isMatchString($ext, 'css') ? 'css' : null);
                if (!$p) {
                    continue;
                }
                Ad::{$p.'last'}($cdn);
            }

            $items = collect($pluginClass->{$p}(true))->map(function ($item) use ($pluginClass, $asPublicForm) {
                return $pluginClass->getCssJsUrl($item, $asPublicForm);
            });
            if (!$items->isEmpty()) {
                foreach ($items as $item) {
                    Ad::{$p.'last'}($item);
                }
            }
        }
    }
}
