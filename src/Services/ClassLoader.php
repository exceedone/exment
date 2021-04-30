<?php
namespace Exceedone\Exment\Services;

/**
 * Loading class for plugin.
 * *Plugin needs require, cannot use composer autoload.*
 * https://se-tomo.com/2018/12/19/%E3%80%90php%E3%80%91spl_autoload_register%E3%81%A8%E3%82%AA%E3%83%BC%E3%83%88%E3%83%AD%E3%83%BC%E3%83%89/
 */
class ClassLoader
{
    /**
     * Check target dirs
     *
     * @var array
     */
    protected $dirs = [];

    /**
     * Already called class
     *
     * @var array
     */
    protected $called = [];

    public function register()
    {
        spl_autoload_register(array($this, 'loadClass'));
    }
  
    public function registerDir(string $dir, string $baseNamespace)
    {
        $this->dirs[] = [
            'dir' => $dir,
            'baseNamespace' => $baseNamespace,
        ];

        return $this;
    }

    public function loadClass($class)
    {
        // if already has called, exit,
        if (in_array($class, $this->called)) {
            return;
        }
        // if namespace is "Exceedone\Exment\Model\Class_", not file, return
        if (strpos($class, "Exceedone\Exment\Model\Class_") === 0) {
            return;
        }

        $this->called[] = $class;

        foreach ($this->dirs as $dir) {
            // get filepath
            $files = $this->getFilePaths($dir['dir'], $dir['baseNamespace'], $class);
            foreach($files as $file){
                require_once $file;
            }
        }
    }


    /**
     * Get file path for require.
     *
     * @param string $dir
     * @param string $baseNamespace
     * @param string $class
     * @return array require target files
     */
    protected function getFilePaths($dir, $baseNamespace, $class) : array
    {
        // get default class path
        $defaultClassPath = $class . '.php';

        // removing base namespace class
        $removingClassPath = path_ltrim(str_replace($baseNamespace, '', $class), '')  . '.php';

        $result = [];
        foreach ([$defaultClassPath, $removingClassPath] as $path) {
            $file = path_join($dir, $path);
            if (!is_readable($file)) {
                continue;
            }
            
            $pathinfo = pathinfo($file);
            if (strpos($pathinfo['basename'], 'blade.php') !== false) {
                continue;
            }
            $result[] = $file;

            // Append global php file
            $this->getGlobalFilesByConfigJson($dir, $result);
        }
        
        return $result;
    }

    /**
     * Get global files by json. For plugin.
     *
     * @return void
     */
    protected function getGlobalFilesByConfigJson($dir, &$result)
    {
        $config_path = path_join($dir, 'config.json');
        if (!is_readable($config_path)) {
            return;
        }

        // get config.json
        $config = \File::get($config_path);
        if(!$config || !is_json($config)){
            return;
        }

        // check "unclass_phps" value
        $json = json_decode($config, true);
        if(!array_key_value_exists('unclass_phps', $json)){
            return;
        }

        foreach(stringToArray($json['unclass_phps']) as $global_php){
            // php_file
            $global_php_file = path_join($dir, $global_php);
            if (!is_readable($global_php_file)) {
                continue;
            }
            
            $ext = pathinfo($global_php_file, PATHINFO_EXTENSION);
            if ($ext != 'php') {
                continue;
            }

            // append $result
            $result[] = $global_php_file;
        }
    }
}
