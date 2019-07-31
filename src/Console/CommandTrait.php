<?php
namespace Exceedone\Exment\Console;

use Exceedone\Exment\Middleware;
use Exceedone\Exment\Enums\SystemTableName;

trait CommandTrait
{
    protected function initExmentCommand()
    {
        Middleware\Morph::defineMorphMap();

        $dbSetting = canConnection() && hasTable(SystemTableName::SYSTEM);
        Middleware\Initialize::initializeConfig($dbSetting);
    }

    /**
     * Publish static files
     *
     * @return void
     */
    public function publishStaticFiles()
    {
        $this->call('vendor:publish', ['--provider' => \Exceedone\Exment\ExmentServiceProvider::class, '--tag' => 'laravel-admin-lang-exment', '--force' => true]);
        $this->call('vendor:publish', ['--provider' => \Exceedone\Exment\ExmentServiceProvider::class, '--tag' => 'laravel-admin-assets-exment', '--force' => true]);
        $this->call('vendor:publish', ['--provider' => \Exceedone\Exment\ExmentServiceProvider::class, '--tag' => 'public', '--force' => true]);
        $this->call('vendor:publish', ['--provider' => \Exceedone\Exment\ExmentServiceProvider::class, '--tag' => 'lang', '--force' => true]);
        $this->call('vendor:publish', ['--provider' => \Exceedone\Exment\ExmentServiceProvider::class, '--tag' => 'views_vendor', '--force' => true]);
        
        // not force
        $this->call('vendor:publish', ['--provider' => \Exceedone\Exment\ExmentServiceProvider::class, '--tag' => 'lang_vendor']);
    }
}
