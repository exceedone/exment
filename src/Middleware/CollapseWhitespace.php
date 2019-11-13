<?php

namespace Exceedone\Exment\Middleware;

use RenatoMarinho\LaravelPageSpeed\Middleware\CollapseWhitespace as CollapseWhitespaceBase;

class CollapseWhitespace extends CollapseWhitespaceBase
{
    /**
     * Should Process
     *
     * @param  \Illuminate\Http\Request $request
     * @param  \Illuminate\Http\Response $response
     * @return bool
     */
    protected function shouldProcessPageSpeed($request, $response)
    {
        if (!parent::shouldProcessPageSpeed($request, $response)) {
            return false;
        }

        // only html, json
        $contentType = $response->headers->get('Content-Type');
        if (!in_array($contentType, ['text/html', 'application/json'])) {
            return false;
        }

        return true;
    }
}
