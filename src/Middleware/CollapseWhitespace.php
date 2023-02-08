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

        if (!boolval(config('exment.remove_response_space', false))) {
            return false;
        }

        // if has textarea, return false
        if (strpos($response->getContent(), '<textarea') !== false) {
            return false;
        }

        // only html, json
        $contentType = $response->headers->get('Content-Type');
        foreach (['text/html', 'application/json'] as $content) {
            if (strpos($contentType, $content) !== false) {
                return true;
            }
        }
    }
}
