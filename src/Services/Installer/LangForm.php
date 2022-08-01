<?php

namespace Exceedone\Exment\Services\Installer;

use Exceedone\Exment\Enums\InitializeStatus;
use Exceedone\Exment\Enums\SystemLocale;
use Exceedone\Exment\Enums\Timezone;

/**
 *
 */
class LangForm
{
    use EnvTrait;

    public function index()
    {
        \Artisan::call('exment:publish');

        return view('exment::install.lang', [
            'locale_options' => SystemLocale::getLocaleOptions(),
            'timezone_options' => Timezone::TIMEZONE,
            'locale_default' => config('exment.default_locale', 'ja'),
            'timezone_default' => config('exment.default_timezone', 'Asia_Tokyo'),
        ]);
    }

    public function post()
    {
        $request = request();

        $rules = [
            'locale' => 'required',
        ];

        $validation = \Validator::make($request->all(), $rules);
        if ($validation->fails()) {
            return back()->withInput()->withErrors($validation);
        }

        $inputs = [
            'APP_LOCALE' => $request->get('locale'),
            'APP_TIMEZONE' => str_replace('_', '/', $request->get('timezone')),
        ];

        // try {
        //     $this->setEnv($inputs);
        // } catch (\Exception $ex) {
        //     return back()->withInput()->withErrors([
        //         'common_error' => exmtrans('install.error.cannot_write_env'),
        //     ]);
        // }

        InstallService::setInputParams($inputs);
        InstallService::setInitializeStatus(InitializeStatus::LANG);

        return redirect(admin_url('install'));
    }
}
