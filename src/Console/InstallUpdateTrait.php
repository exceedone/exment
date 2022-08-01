<?php

namespace Exceedone\Exment\Console;

trait InstallUpdateTrait
{
    /**
     * Install directory.
     *
     * @var string
     */
    protected $directory = '';

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
        $this->call('vendor:publish', ['--provider' => \Exceedone\Exment\ExmentServiceProvider::class, '--tag' => 'views_vendor', '--force' => true]);

        // not force
        $this->call('vendor:publish', ['--provider' => \Exceedone\Exment\ExmentServiceProvider::class, '--tag' => 'lang_vendor']);
    }

    /**
     * Create routes file.
     *
     * @return void
     */
    protected function createExmentBootstrapFile()
    {
        $this->directory = config('exment.directory');

        $this->makeExmentDir();

        $file = path_join($this->directory, 'bootstrap.php');

        if (\File::exists($file)) {
            return;
        }

        $contents = $this->getExmentStub('bootstrap');

        $this->laravel['files']->put($file, $contents);
        $this->line('<info>Bootstrap file was created:</info> '.str_replace(base_path(), '', $file));
    }

    /**
     * Get stub contents.
     *
     * @param string $name
     *
     * @return string
     */
    protected function getExmentStub($name)
    {
        return $this->laravel['files']->get(path_join(__DIR__, "stubs", "$name.stub"));
    }

    /**
     * Make new directory.
     *
     * @param string $path
     */
    protected function makeExmentDir($path = '')
    {
        $dirpath = $this->directory;

        if (\File::exists($dirpath)) {
            return;
        }

        $this->laravel['files']->makeDirectory($dirpath, 0755, true, true);
    }
}
