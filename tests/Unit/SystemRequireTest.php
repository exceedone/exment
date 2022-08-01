<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Services\SystemRequire;
use Exceedone\Exment\Enums\SystemRequireResult;

class SystemRequireTest extends UnitTestBase
{
    protected function init()
    {
        $this->initAllTest();
    }


    public function testMemoryValue()
    {
        return $this->_test(SystemRequire\MemorySize::class, function () {
            return str_replace('m', '', str_replace('M', '', ini_get('memory_limit')));
        });
    }

    public function testFileUploadSize()
    {
        return $this->_test(SystemRequire\FileUploadSize::class, function () {
            return  \Exment::getUploadMaxFileSize();
        });
    }

    public function testFilePermission()
    {
        return $this->_test(SystemRequire\FilePermission::class, function () {
            return [];
        }, function ($value, $exceptValue) {
            $this->assertTrue(count($value) == count($exceptValue));
        });
    }


    public function testFilePermissionInstaller()
    {
        return $this->_test(SystemRequire\FilePermissionInstaller::class, function () {
            return [];
        }, function ($value, $exceptValue) {
            $this->assertTrue(count($value) == count($exceptValue));
        });
    }

    public function testBackupRestore()
    {
        return $this->_test(SystemRequire\BackupRestore::class, function () {
            if (\Exment::isSqlServer()) {
                return SystemRequireResult::WARNING;
            }
            return SystemRequireResult::OK;
        });
    }

    public function testComposer()
    {
        return $this->_test(SystemRequire\Composer::class, function () {
            //$command = 'composer --version';
            $command = 'composer%s --version';

            foreach ([''] as $suffix) {
                $c = sprintf($command, $suffix);
                exec($c, $output, $return_var);
                if ($return_var == 0) {
                    return SystemRequireResult::OK;
                }
            }
            return SystemRequireResult::WARNING;
        });
    }

    public function testComposerVersionMin()
    {
        return $this->_test(SystemRequireTestComposerMin::class, function () {
            return SystemRequireResult::WARNING;
        });
    }


    protected function _test($classname, \Closure $exceptValueFunc, ?\Closure $resultFunc = null)
    {
        $obj = new $classname();
        $value = $obj->getResult();
        $exceptValue = $exceptValueFunc();

        if ($resultFunc) {
            $result = $resultFunc($value, $exceptValue);
        } else {
            $result = isMatchString($exceptValue, $value);
            $this->assertTrue($result, "Except value is $value, but value is $exceptValue.");
        }
    }
}
