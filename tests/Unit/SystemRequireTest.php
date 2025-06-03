<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Services\SystemRequire;
use Exceedone\Exment\Enums\SystemRequireResult;

class SystemRequireTest extends UnitTestBase
{
    /**
     * @return null
     */
    protected function init()
    {
        $this->initAllTest();
    }


    /**
     * @return null
     */
    public function testMemoryValue()
    {
        /** @phpstan-ignore-next-line Result of method
         * Exceedone\Exment\Tests\Unit\SystemRequireTest::_test() (void) is
         * used.  */
        return $this->_test(SystemRequire\MemorySize::class, function () {
            return str_replace('m', '', str_replace('M', '', ini_get('memory_limit')));
        });
    }

    /**
     * @return null
     */
    public function testFileUploadSize()
    {
        /** @phpstan-ignore-next-line Result of method
         * Exceedone\Exment\Tests\Unit\SystemRequireTest::_test() (void) is
         * used.  */
        return $this->_test(SystemRequire\FileUploadSize::class, function () {
            return  \Exment::getUploadMaxFileSize();
        });
    }

    /**
     * @return null
     */
    public function testFilePermission()
    {
        /** @phpstan-ignore-next-line Result of method
         * Exceedone\Exment\Tests\Unit\SystemRequireTest::_test() (void) is
         * used.  */
        return $this->_test(SystemRequire\FilePermission::class, function () {
            return [];
        }, function ($value, $exceptValue) {
            $this->assertTrue(count($value) == count($exceptValue));
        });
    }


    /**
     * @return null
     */
    public function testFilePermissionInstaller()
    {
        /** @phpstan-ignore-next-line Result of method
         * Exceedone\Exment\Tests\Unit\SystemRequireTest::_test() (void) is
         * used.  */
        return $this->_test(SystemRequire\FilePermissionInstaller::class, function () {
            return [];
        }, function ($value, $exceptValue) {
            $this->assertTrue(count($value) == count($exceptValue));
        });
    }

    /**
     * @return null
     */
    public function testBackupRestore()
    {
        /** @phpstan-ignore-next-line Result of method
         * Exceedone\Exment\Tests\Unit\SystemRequireTest::_test() (void) is
         * used.  */
        return $this->_test(SystemRequire\BackupRestore::class, function () {
            if (\Exment::isSqlServer()) {
                return SystemRequireResult::WARNING;
            }
            return SystemRequireResult::OK;
        });
    }

    /**
     * @return null
     */
    public function testComposer()
    {
        /** @phpstan-ignore-next-line Result of method
         * Exceedone\Exment\Tests\Unit\SystemRequireTest::_test() (void) is
         * used.  */
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

    /**
     * @return null
     */
    public function testComposerVersionMin()
    {
        /** @phpstan-ignore-next-line Result of method
         * Exceedone\Exment\Tests\Unit\SystemRequireTest::_test() (void) is
         * used.  */
        return $this->_test(SystemRequireTestComposerMin::class, function () {
            return SystemRequireResult::WARNING;
        });
    }


    /**
     * @param string $classname
     * @param \Closure $exceptValueFunc
     * @param \Closure|null $resultFunc
     * @return void
     */
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
