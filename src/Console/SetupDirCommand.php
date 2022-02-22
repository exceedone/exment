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
    protected $signature = 'exment:setup-dir {--user=} {--group=}';

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
        // If not Windows, get user and group
        if(!\Exment::isWindows()){
            $user = $this->option('user');
            if(!$user){
                $current_user = get_current_user();
                $user = $this->ask("Please input user name. [{$current_user}]");
                if(!$user){
                    $user = $current_user;
                }
            }

            $group = $this->getGroup();
            if(!$group){
                return;
            }
        }

        static::createSystemFolder($user ?? null, $group ?? null);
    }


    protected function getGroup()
    {
        $group = $this->option('group');
        if(!$group){
            // get current group
            $current_group = null;
            if(function_exists('posix_getgrgid')){
                $current_group = array_get(posix_getgrgid(filegroup(base_path(path_join('public', 'index.php')))), 'name');
            }
            
            $ask = !is_nullorempty($current_group) ? "Please input group name. [{$current_group}]" : "Please input group name.";
            $group = $this->ask($ask);
            if(!$group){
                $group = $current_group;
            }

            if(!$group){
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
    public static function createSystemFolder(?string $user, ?string $group){
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
        }
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
    protected static function addPermission(string $path, ?string $user, ?string $group, bool $isMod = true){
        $path = base_path($path);

        $dirs = \Exment::allDirectories($path);
        foreach($dirs as $dir){
            chown($dir, $user);
            chgrp($dir, $group);
            if($isMod){
                chmod($dir, 0775);
            }
        }
        
        $files = \File::allFiles($path);
        foreach($files as $file){
            chown($file, $user);
            chgrp($file, $group);
            if($isMod){
                chmod($file, 0664);
            }
        }
    }


}
