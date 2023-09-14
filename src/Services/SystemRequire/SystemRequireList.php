<?php

namespace Exceedone\Exment\Services\SystemRequire;

use Exceedone\Exment\Enums\SystemRequireResult;
use Exceedone\Exment\Enums\SystemRequireCalledType;
use Illuminate\Support\Collection;

/**
 * System require check base
 */
class SystemRequireList
{
    protected $items = [];

    protected static $requireClassesCommand = [
        MemorySize::class,
        MaxInputVars::class,
        FileUploadSize::class,
        TimeoutTime::class,
        FilePermission::class,
        Composer::class,
        BackupRestore::class,
    ];

    protected static $requireClassesWeb = [
        MemorySize::class,
        MaxInputVars::class,
        FileUploadSize::class,
        TimeoutTime::class,
        FilePermission::class,
        Composer::class,
        BackupRestore::class,
    ];

    protected static $requireClassesInstallWeb = [
        MemorySize::class,
        MaxInputVars::class,
        FileUploadSize::class,
        TimeoutTime::class,
        FilePermissionInstaller::class,
        Composer::class,
        BackupRestore::class,
    ];


    protected static function getRequireClasses(string $systemRequireCalledType)
    {
        switch ($systemRequireCalledType) {
            case SystemRequireCalledType::COMMAND:
                return static::$requireClassesCommand;
            case SystemRequireCalledType::INSTALL_WEB:
                return static::$requireClassesInstallWeb;
            case SystemRequireCalledType::WEB:
                return static::$requireClassesWeb;
        }

        return static::$requireClassesCommand;
    }


    public function setItem($item)
    {
        $this->items[] = $item;
        return $this;
    }


    public function getItems()
    {
        return $this->items;
    }


    public function hasResultWarning(): bool
    {
        return collect($this->items)->contains(function ($item) {
            return $item->checkResult() == SystemRequireResult::WARNING;
        });
    }
    public function hasResultNg(): bool
    {
        return collect($this->items)->contains(function ($item) {
            return $item->checkResult() == SystemRequireResult::NG;
        });
    }

    /**
     * Get require objects.
     *
     * @param string $systemRequireCalledType
     * @return SystemRequireList
     */
    public static function make(string $systemRequireCalledType): SystemRequireList
    {
        $result = new self();

        $classes = static::getRequireClasses($systemRequireCalledType);
        foreach ($classes as $className) {
            $obj = new $className();
            $obj->systemRequireCalledType($systemRequireCalledType);

            $result->setItem($obj);
        }

        return $result;
    }
}
