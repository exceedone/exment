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
            $file = $this->getFilePath($dir['dir'], $dir['baseNamespace'], $class);
            if (!$file) {
                continue;
            }
            try {
                require_once $file;
            } catch (\Throwable $th) {
                admin_error_once(exmtrans('common.error'), exmtrans('error.class_load_error', $file, $th->getMessage()));
                \Log::error($th);
            }
        }
    }


    /**
     * Get file path for require.
     *
     * @param string $dir
     * @param string $baseNamespace
     * @param string $class
     * @return string|null
     */
    protected function getFilePath($dir, $baseNamespace, $class): ?string
    {
        // get default class path
        $defaultClassPath = $class . '.php';

        // removing base namespace class
        $removingClassPath = path_ltrim(str_replace($baseNamespace, '', $class), '')  . '.php';

        foreach ([$defaultClassPath, $removingClassPath] as $path) {
            $file = path_join_os($dir, $path);
            $file = \Exment::replaceOsSeparator($file);

            if (!is_readable($file)) {
                continue;
            }

            $pathinfo = pathinfo($file);
            if (strpos($pathinfo['basename'], 'blade.php') !== false) {
                continue;
            }
            return $file;
        }

        return null;
    }
}
