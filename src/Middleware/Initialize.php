<?php

namespace Exceedone\Exment\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use \Html;

class Initialize
{
    public function handle(Request $request, \Closure $next)
    {
        // Get System config
        $initialized = System::initialized();

        // if path is not "initialize" and not installed, then redirect to initialize
        if (!$this->shouldPassThrough($request) && !$initialized) {
            $request->session()->invalidate();
            return redirect()->guest(admin_base_path('initialize'));
        }
        // if path is "initialize" and installed, redirect to login
        elseif ($this->shouldPassThrough($request) && $initialized) {
            return redirect()->guest(admin_base_path('auth/login'));
        }

        // Set system setting to config --------------------------------------------------
        // Site Name
        $val = System::site_name();
        if (isset($val)) {
            Config::set('admin.name', $val);
        }

        // Logo
        $val = System::site_logo();
        if (isset($val)) {
            Config::set('admin.logo', Html::image($val, 'header logo'));
        } else {
            $val = System::site_name();
            if (isset($val)) {
                Config::set('admin.logo', esc_html($val));
            }
        }

        // Logo(Short)
        $val = System::site_logo_mini();
        if (isset($val)) {
            Config::set('admin.logo-mini', Html::image($val, 'header logo mini'));
        } else {
            $val = System::site_name_short();
            if (isset($val)) {
                Config::set('admin.logo-mini', esc_html($val));
            }
        }

        // Site Skin
        $val = System::site_skin();
        if (isset($val)) {
            Config::set('admin.skin', esc_html($val));
        }
        // Site layout
        $val = System::site_layout();
        if (isset($val)) {
            Config::set('admin.layout', array_get(Define::SYSTEM_LAYOUT, $val));
        }

        return $next($request);
    }

    /**
     * Determine if the request has a URI that should pass through verification.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return bool
     */
    protected function shouldPassThrough($request)
    {
        $excepts = [
            //admin_base_path('auth/login'),
            //admin_base_path('auth/logout'),
            admin_base_path('initialize')
        ];

        foreach ($excepts as $except) {
            if ($except !== '/') {
                $except = trim($except, '/');
            }

            if ($request->is($except)) {
                return true;
            }
        }

        return false;
    }
}
