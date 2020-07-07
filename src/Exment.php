<?php

namespace Exceedone\Exment;

use Exceedone\Exment\Enums\UrlTagType;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Illuminate\Support\Facades\Auth;
use Encore\Admin\Admin;

/**
 * Class Admin.
 */
class Exment
{
    /**
     * Left sider-bar menu.
     *
     * @return array
     */
    public function menu()
    {
        return (new Menu())->toTree();
    }

    public static function error($request, $exception, $callback)
    {
        if (isApiEndpoint()) {
            return $callback($request, $exception);
        }
        if (!$request->pjax() && $request->ajax()) {
            // if memory error, throw ajax response
            if (strpos($exception->getMessage(), 'Allowed memory size of') === 0) {
                $manualUrl = getManualUrl('quickstart_more');
                return getAjaxResponse([
                    'result'  => false,
                    'errors' => ['import_error_message' => ['type' => 'input', 'message' => exmtrans('error.memory_leak', ['url' => $manualUrl]) ]],
                ]);
            }

            return $callback($request, $exception);
        }
        
        try {
            // whether has User
            $user = \Exment::user();
            if (!isset($user)) {
                return $callback($request, $exception);
            }

            $errorController = app(\Exceedone\Exment\Controllers\ErrorController::class);
            return $errorController->error($request, $exception);
        } catch (\Exception $ex) {
            return $callback($request, $exception);
        } catch (\Throwable $e) {
            return $callback($request, $exception);
        }
    }

    /**
     * get user. multi supported admin and adminapi
     */
    public function user($guards = null)
    {
        if (is_null($guards)) {
            $guards = ['adminapi', 'admin'];
        }
        if (is_string($guards)) {
            $guards = [$guards];
        }
        
        foreach ($guards as $guard) {
            # code...
            $user = Auth::guard($guard)->user();
            if (isset($user)) {
                return $user;
            }
        }
        return null;
    }

    /**
     * get exment version
     */
    public function version($getFromComposer = true)
    {
        list($latest, $current) = getExmentVersion($getFromComposer);
        return $current;
    }







    // Helper logic ----------------------------------------------------

    public function getUrlTag(?string $url, ?string $label, $urlTagType, array $attributes = [], array $options = [])
    {
        $options = array_merge(
            [
                'tooltipTitle' => null,
                'notEscape' => false,
            ],
            $options
        );

        if(!boolval($options['notEscape'])){
            $label = esc_html($label);
        }

        // if disable url tag in request, return only url. (for use modal search)
        if(boolval(System::requestSession(Define::SYSTEM_KEY_SESSION_DISABLE_DATA_URL_TAG))){
            return view('exment::widgets.url-nottag', [
                'label' => $label,
            ]);
        }

        $href = $url;
        if($urlTagType == UrlTagType::MODAL){
            $url .= '?modal=1';
            $href = 'javascript:void(0);';
            $options['tooltipTitle'] = exmtrans('custom_value.data_detail');

            $attributes['data-widgetmodal_url'] = $url;
        }
        elseif($urlTagType == UrlTagType::BLANK){
            $attributes['target'] = '_blank';
        }
        elseif($urlTagType == UrlTagType::TOP){
            $attributes['target'] = '_top';
        }

        if(isset($options['tooltipTitle'])){
            $attributes['data-toggle'] = 'tooltip';
            $attributes['title'] = esc_html($options['tooltipTitle']);
        }

        return view('exment::widgets.url-tag', [
            'href' => $href,
            'label' => $label,
            'attributes' => formatAttributes($attributes),
        ]);
    }
}
