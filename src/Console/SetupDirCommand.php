<?php

namespace Exceedone\Exment\Console;

use Encore\Admin\Console\InstallCommand as AdminInstallCommand;

class SetupDirCommand extends AdminInstallCommand
{
    use CommandTrait;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'exment:setup-dir {--user=} {--group=} {--easy=0} {--easy_clear=0}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Setup exment directory';

    /**
     * Install directory.
     *
     * @var string
     */
    protected $directory = '';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->initExmentCommand();
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        if (boolval($this->option('easy_clear'))) {
            static::revertEasyInstall();
            return;
        }

        // If not Windows, get user and group
        if (!\Exment::isWindows()) {
            $user = $this->option('user');
            if (!$user) {
                $current_user = get_current_user();
                $user = $this->ask("Please input user name. [{$current_user}]");
                if (!$user) {
                    $user = $current_user;
                }
            }

            $group = $this->getGroup();
            if (!$group) {
                return;
            }
        }

        static::createSystemFolder($user ?? null, $group ?? null, boolval($this->option('easy')));
    }


    protected function getGroup()
    {
        $group = $this->option('group');
        if (!$group) {
            // get current group
            $current_group = null;
            if (function_exists('posix_getgrgid')) {
                $current_group = array_get(posix_getgrgid(filegroup(base_path(path_join('public', 'index.php')))), 'name');
            }

            $ask = !is_nullorempty($current_group) ? "Please input group name. [{$current_group}]" : "Please input group name.";
            $group = $this->ask($ask);
            if (!$group) {
                $group = $current_group;
            }

            if (!$group) {
                $this->error('Please input group name.');
            }
        }

        return $group;
    }


    /**
     * Create and add permission
     *
     * @param string|null $user
     * @param string|null $group
     * @return void
     */
    public static function createSystemFolder(?string $user, ?string $group, bool $easy = false)
    {
        // create storage/app/purifier
        \Exment::makeDirectory(base_path(path_join('storage', 'app', 'purifier')));
        \Exment::makeDirectory(base_path(path_join('storage', 'app', 'purifier', 'HTML')));
        \Exment::makeDirectory(base_path(path_join('storage', 'app', 'purifier', 'CSS')));
        \Exment::makeDirectory(base_path(path_join('storage', 'app', 'purifier', 'URI')));

        // Add permission if linux
        if (!\Exment::isWindows()) {
            static::addPermission('', $user, $group, false);

            static::addPermission('storage', $user, $group);
            static::addPermission('bootstrap/cache', $user, $group);

            // If easy install, set permission to dir
            if ($easy) {
                static::addPermission('app', $user, $group);
                static::addPermission('config', $user, $group);
                static::addPermission('public', $user, $group);
                static::addPermission('resources', $user, $group);
                static::addPermission('.env', $user, $group);
            }
        }
    }

    /**
     * Revert permission
     *
     * @return void
     */
    public static function revertEasyInstall()
    {
        static::revertPermission('app');
        static::revertPermission('config');
        static::revertPermission('public');
        static::revertPermission('resources');
        static::revertPermission('.env');
    }

    /**
     * Set all permission
     *
     * @param string $path
     * @param string|null $user
     * @param string|null $group
     * @param bool $isMod is execute chmod
     * @return void
     */
    protected static function addPermission(string $path, ?string $user, ?string $group, bool $isMod = true)
    {
        $path = base_path($path);

        if (\File::isDirectory($path)) {
            $dirs = \Exment::allDirectories($path);
            foreach ($dirs as $dir) {
                chown($dir, $user);
                chgrp($dir, $group);
                if ($isMod) {
                    chmod($dir, 02775);
                }
            }

            // Change mod self
            chmod($path, 02775);

            $files = \File::allFiles($path, true);
            foreach ($files as $file) {
                chown($file, $user);
                chgrp($file, $group);
                if ($isMod) {
                    chmod($file, 0664);
                }
            }
        } elseif (\File::exists($path)) {
            chown($path, $user);
            chgrp($path, $group);
            if ($isMod) {
                chmod($path, 0664);
            }
        }
    }

    /**
     * Set all permission
     *
     * @param string $path
     * @return void
     */
    protected static function revertPermission(string $path)
    {
        $path = base_path($path);

        if (\File::isDirectory($path)) {
            $dirs = \Exment::allDirectories($path);
            foreach ($dirs as $dir) {
                chmod($dir, 02755);
            }

            // Change mod self
            chmod($path, 02755);

            $files = \File::allFiles($path);
            foreach ($files as $file) {
                chmod($file, 0644);
            }
        } elseif (\File::exists($path)) {
            chmod($path, 0644);
        }
    }
}
