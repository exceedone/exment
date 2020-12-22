<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Services\SystemRequire;
use Exceedone\Exment\Enums\SystemRequireResult;

class SystemRequireTest extends UnitTestBase
{
    protected function init(){
        $this->initAllTest();
    }


    public function testMemoryValue(){
        return $this->_test(SystemRequire\MemorySize::class, function(){
            return str_replace('m', '', str_replace('M', '', ini_get('memory_limit')));
        });
    }

    public function testFileUploadSize(){
        return $this->_test(SystemRequire\FileUploadSize::class, function(){
            return  \Exment::getUploadMaxFileSize();
        });
    }

    public function testFilePermission(){
        return $this->_test(SystemRequire\FilePermission::class, function(){
            return [];
        }, function($value, $exceptValue){
            $this->assertTrue(count($value) == count($exceptValue));
        }, );
    }


    public function testFilePermissionInstaller(){
        return $this->_test(SystemRequire\FilePermissionInstaller::class, function(){
            return [];
        }, function($value, $exceptValue){
            $this->assertTrue(count($value) == count($exceptValue));
        });
    }

    public function testBackupRestore(){
        return $this->_test(SystemRequire\BackupRestore::class, function(){
            if(\Exment::isSqlServer()){
                return SystemRequireResult::WARNING;
            }
            return SystemRequireResult::OK;
        });
    }


    protected function _test($classname, \Closure $exceptValueFunc, ?\Closure $resultFunc = null){
        $obj = new $classname();
        $value = $obj->getResult();
        $exceptValue = $exceptValueFunc();

        if($resultFunc){
            $result = $resultFunc($value, $exceptValue);
        }
        else{
            $result = isMatchString($exceptValue, $value);
            $this->assertTrue($result, "Except value is $value, but value is $exceptValue.");
        }
        
    }
}
