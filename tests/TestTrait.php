<?php

namespace Exceedone\Exment\Tests;

use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\CustomTable;

trait TestTrait
{
    /**
     * Assert that the response is a superset of the given JSON.
     *
     * @param  array  $data1
     * @param  array  $data2
     * @param  bool  $strict
     * @return $this
     */
    public function assertJsonExment(array $data1, $data2, $strict = false)
    {
        \PHPUnit\Framework\Assert::assertArraySubset(
            $data1, $data2, $strict
        );

        return $this;
    }

    protected function assertMatch($value1, $value2){
        $isMatch = false;

        $messageV1 = is_array($value1) ? json_encode($value1) : $value1;
        $messageV2 = is_array($value2) ? json_encode($value2) : $value2;
        $this->assertTrue($value1 == $value2, "value1 is $messageV1, but value2 is $messageV2");

        return $this;
    }

    /**
     * Skip test temporarily.
     *
     * @param \Closure $skipMatchFunc Checking function. Please return boolean.
     * @param string $messsage showing message why this test is skipped.
     * @return void
     */
    protected function skipTempTestIfTrue($skipMatch, string $messsage = null){
        $result = null;
        if($skipMatch instanceof \Closure){
            $result = $skipMatch();
        }
        elseif(is_bool($skipMatch)){
            $result = $skipMatch;
        }
        else{
            throw new \Exception('skipTempTestIfTrue is only bool or Closure');
        }

        if($result){
            $this->markTestSkipped('This function is temporarily skipped. ' . $messsage);
        }
    }

    /**
     * Skip test everytime.
     *
     * @param string $messsage showing message why this test is skipped.
     * @return void
     */
    protected function skipTempTest(string $messsage = null){
        $this->markTestSkipped('This function is temporarily skipped. ' . $messsage);
    }

    /**
     * Initialize all test
     *
     * @return void
     */
    protected function initAllTest(){
        System::clearCache();
        \Exceedone\Exment\Middleware\Morph::defineMorphMap();
        \Exceedone\Exment\Middleware\ExmentDebug::handleLog();
    }

    
    /**
     * Check custom value's permission after getting api
     *
     * @param CustomTable $custom_table
     * @param array $ids
     * @param \Closure|null $filterCallback
     * @return void
     */
    protected function checkCustomValuePermission(CustomTable $custom_table, $ids, ?\Closure $filterCallback = null)
    {
        // get all ids
        $allIds = \DB::table(getDBTableName($custom_table))->select('id')->pluck('id');
        $query = $custom_table->getValueModel()->withoutGlobalScopes();
        
        if($filterCallback){
            $filterCallback($query);
        }
        $all_custom_values = $query->findMany($allIds);
        
        foreach($all_custom_values as $all_custom_value){
            // if find target user ids, check has permisison
            $hasPermission = in_array($all_custom_value->id, $ids);
            $hasPermissionString = $hasPermission ? 'true' : 'false';

            $this->assertTrue($hasPermission === $custom_table->hasPermissionData($all_custom_value->id), "id {$all_custom_value->id}'s permission expects {$hasPermissionString}, but wrong.");
        }
    }

    
    protected function getTextDirPath() : string
    {
        $dir = storage_path('app/tests');
        if(!\File::exists($dir)){
            \File::makeDirectory($dir);
        }

        return $dir;
    }

    protected function getTextFilePath($fileName = 'file.txt') : string
    {
        $dir = $this->getTextDirPath();

        // create file
        $file = path_join($dir, $fileName);
        if(!\File::exists($file)){
            \File::put($file, TestDefine::FILE_BASE64);
        }
        return $file;
    }

    protected function getTextImagePath($imageName = 'image.png'){
        $dir = $this->getTextDirPath();
        // create file
        $file = path_join($dir, $imageName);
        if(!\File::exists($file)){
            // convert to base64. This string is 1*1 rad color's image
            $f = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsIAAA7CARUoSoAAAAANSURBVBhXY3gro/IfAAVUAi3GPZKdAAAAAElFTkSuQmCC');
            \File::put($file, $f);
        }
        return $file;
    }
    
    protected function getTextFileObject($fileName = 'file.txt')
    {
        $file = $this->getTextFilePath($fileName);
        return \File::get($file);
    }

    protected function getTextImageObject($imageName = 'image.png')
    {
        $file = $this->getTextImagePath($imageName);
        return \File::get($file);
    }

    
    protected function callProtectedMethod($obj, $methodName, ...$args){
        $method = new \ReflectionMethod(get_class($obj), $methodName);
        $method->setAccessible(true);
        return $method->invoke($obj, ...$args);
    }

    protected function callStaticProtectedMethod($className, $methodName, ...$args){
        $method = new \ReflectionMethod($className, $methodName);
        $method->setAccessible(true);
        return $method->invokeArgs(null, $args);
    }
}
