<?php
namespace Exceedone\Exment\Services\Installer;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Enums\InitializeStatus;

/**
 *
 */
class LangForm
{
    use InstallFormTrait;

    public function index()
    {
        \Artisan::call('exment:publish');
        
        return view('exment::install.lang', [
            'locale_options' => ['ja' => '日本語', 'en' => 'English'],
            'timezone_options' => Define::TIMEZONE,
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

        $this->setEnv($inputs);

        InstallService::setInitializeStatus(InitializeStatus::LANG);

        \Artisan::call('cache:clear');
        \Artisan::call('config:clear');

        return redirect(admin_url('install'));
    }
}
