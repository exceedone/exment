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
    // @phpstan-ignore-next-line
    protected $items = [];

    // @phpstan-ignore-next-line
    protected static $requireClassesCommand = [
        MemorySize::class,
        MaxInputVars::class,
        FileUploadSize::class,
        TimeoutTime::class,
        FilePermission::class,
        Composer::class,
        BackupRestore::class,
    ];

    // @phpstan-ignore-next-line
    protected static $requireClassesWeb = [
        MemorySize::class,
        MaxInputVars::class,
        FileUploadSize::class,
        TimeoutTime::class,
        FilePermission::class,
        Composer::class,
        BackupRestore::class,
    ];

    // @phpstan-ignore-next-line
    protected static $requireClassesInstallWeb = [
        MemorySize::class,
        MaxInputVars::class,
        FileUploadSize::class,
        TimeoutTime::class,
        FilePermissionInstaller::class,
        Composer::class,
        BackupRestore::class,
    ];


    /**
     * The tuning advice (ISS00414). Shown on the tuning page, on the install screen
     * and by exment:checkrequire, apart from the requirements: it never blocks the install.
     *
     * @var array<class-string>
     */
    protected static $tuningClasses = [
        Tuning\OpcacheTuning::class,
        Tuning\PhpVersionTuning::class,
        Tuning\AppDebugTuning::class,
        Tuning\ConfigCacheTuning::class,
        Tuning\ComposerAutoloadTuning::class,
        Tuning\ExmentCacheTuning::class,
        Tuning\CacheDriverTuning::class,
        Tuning\QueueTuning::class,
        Tuning\DebugLogTuning::class,
        Tuning\SearchIndexTuning::class,
        Tuning\DashboardCacheTuning::class,
        // what was found slow on a server (ISS02823)
        Tuning\ScheduleTuning::class,
        Tuning\PhpFpmTuning::class,
        Tuning\RouteCacheTuning::class,
        Tuning\FilePermissionTuning::class,
        Tuning\AssetsTuning::class,
        Tuning\SlowQueryLogTuning::class,
        Tuning\IndexMissingTuning::class,
    ];

    // @phpstan-ignore-next-line
    protected static function getRequireClasses(string $systemRequireCalledType)
    {
        switch ($systemRequireCalledType) {
            case SystemRequireCalledType::COMMAND:
                return static::$requireClassesCommand;
            case SystemRequireCalledType::INSTALL_WEB:
                return static::$requireClassesInstallWeb;
            case SystemRequireCalledType::WEB:
                return static::$requireClassesWeb;
            case SystemRequireCalledType::TUNING:
                return static::$tuningClasses;
        }

        return static::$requireClassesCommand;
    }


    // @phpstan-ignore-next-line
    public function setItem($item)
    {
        $this->items[] = $item;
        return $this;
    }


    // @phpstan-ignore-next-line
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
            // @phpstan-ignore-next-line
            $obj->systemRequireCalledType($systemRequireCalledType);

            $result->setItem($obj);
        }

        return $result;
    }
}
