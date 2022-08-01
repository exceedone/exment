<?php

namespace Exceedone\Exment\Validator;

use Illuminate\Contracts\Validation\Rule;
use Exceedone\Exment\Storage\Disk\DiskServiceItem;

/**
 * PluginNamespaceRule.
 * Check namespace rule.
 */
class PluginNamespaceRule implements Rule
{
    protected $errors = [];

    /**
     *
     * @var DiskServiceItem
     */
    protected $tmpDiskItem;
    /**
     *
     * @var string
     */
    protected $basePath;

    public function __construct(DiskServiceItem $tmpDiskItem, string $basePath)
    {
        $this->tmpDiskItem = $tmpDiskItem;
        $this->basePath = $basePath;
    }

    /**
    * Check Validation
    *
    * @param  string  $attribute
    * @param  mixed  $value
    * @return bool
    */
    public function passes($attribute, $value)
    {
        // get all files
        $disk = $this->tmpDiskItem->disk();
        $files = $disk->allFiles($this->basePath);
        foreach ($files as $file) {
            $pathinfo = pathinfo($file);
            // check php(not contains blade.php)
            if (!isMatchString(array_get($pathinfo, 'extension'), 'php')) {
                continue;
            }
            if (strpos(array_get($pathinfo, 'basename'), 'blade.php') !== false) {
                continue;
            }

            // define namespace
            $basePath = path_ltrim($file, $this->basePath);
            $baseName = array_get($pathinfo, 'basename');
            $dirPath = path_rtrim($basePath, $baseName);

            // get namespace
            $namespaces = array_filter(explode('/', \Exment::replaceBackToSlash($dirPath)));
            array_unshift($namespaces, "App", "Plugins", pascalize($value));

            $namespace = "namespace +" . implode("\\\\", $namespaces);

            // read php file
            $phpFile = $disk->get($file);

            // find namespace. and not match, set errors file name.
            if (!preg_match('/' . $namespace . '/u', $phpFile)) {
                $this->errors[] = $basePath;
            }
        }

        return count($this->errors) == 0;
    }

    /**
     * get validation error message
     *
     * @return string
     */
    public function message()
    {
        $classes = implode(exmtrans('common.separate_word'), $this->errors);
        return exmtrans('plugin.error.class_wrongnamespace', ['classes' => $classes]);
    }
}
