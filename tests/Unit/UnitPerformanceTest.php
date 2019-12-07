<?php

namespace Exceedone\Exment\Tests\Unit;

use Tests\TestCase;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Enums\SystemTableName;

class UnitPerformanceTest extends TestCase
{
    public function testGetEloquentPerformance()
    {
        $time_start = microtime(true);

        foreach([1, 10, 1000] as $count){
            $this->showMicrotimeLog('CustomTable getEloquent' . $count, function() use($count){
                for($i = 0; $i < $count; $i++){
                    CustomTable::getEloquent(SystemTableName::USER);
                }

                System::clearCache();
                System::clearRequestSession();
            });
            
            $this->showMicrotimeLog('CustomTable whereFirst' . $count, function() use($count){
                for ($i = 0; $i < $count; $i++) {
                    CustomTable::where('table_name', SystemTableName::USER)->first();
                }
                
                System::clearCache();
                System::clearRequestSession();
            });
            
            $this->showMicrotimeLog('CustomColumn getEloquent' . $count, function() use($count){
                for($i = 0; $i < $count; $i++){
                    CustomColumn::getEloquent('user_name', SystemTableName::USER);
                }

                System::clearCache();
                System::clearRequestSession();
            });
            
            $this->showMicrotimeLog('CustomColumn whereFirst' . $count, function() use($count){
                for ($i = 0; $i < $count; $i++) {
                    $custom_table = CustomTable::where('table_name', SystemTableName::USER)->first();
                    CustomColumn::where('custom_table_id', $custom_table->id)->first();
                }
                
                System::clearCache();
                System::clearRequestSession();
            });
        }

        $this->assertTrue(true);
    }

    protected function showMicrotimeLog($funcName, $callback){
        $time_start = microtime(true);
        
        $callback();

        $time = microtime(true) - $time_start;

        $millisecond = $time * 1000;
        \Log::debug("{$funcName} : {$millisecond} millisecond");
    }
}
