<?php

namespace Exceedone\Exment\Middleware;

use Encore\Admin\Facades\Admin as Ad;

trait BootstrapTrait
{
    protected function setCssJsList(array $list, bool $isCss)
    {
        $ver = \Exment::getExmentCurrentVersion();
        if (!isset($ver)) {
            $ver = date('YmdHis');
        }
        
        $func = ($isCss ? 'css' : 'js');
        foreach ($list as $l) {
            Ad::{$func}(asset($l . '?ver='.$ver));
        }
    }
    

    protected function isStaticRequest($request)
    {
        $pathInfo = $request->getPathInfo();
        $extension = strtolower(pathinfo($pathInfo, PATHINFO_EXTENSION));
        return in_array($extension, ['js', 'css', 'png', 'jpg', 'jpeg', 'gif']);
    }
}
