<?php

namespace Exceedone\Exment\Tests\Unit;

use Tests\TestCase;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Enums\SystemTableName;

class UnitPerformanceTest extends TestCase
{
    public function testGetEloquentPerformance()
    {
        // $time_start = microtime(true);

        // foreach([1, 10, 1000] as $count){
        //     $this->showMicrotimeLog('CustomTable getEloquent' . $count, function() use($count){
        //         for($i = 0; $i < $count; $i++){
        //             CustomTable::getEloquent(SystemTableName::USER);
        //         }

        //         System::clearCache();
        //         System::clearRequestSession();
        //     });

        //     $this->showMicrotimeLog('CustomTable whereFirst' . $count, function() use($count){
        //         for ($i = 0; $i < $count; $i++) {
        //             CustomTable::where('table_name', SystemTableName::USER)->first();
        //         }

        //         System::clearCache();
        //         System::clearRequestSession();
        //     });

        //     $this->showMicrotimeLog('CustomColumn getEloquent' . $count, function() use($count){
        //         for($i = 0; $i < $count; $i++){
        //             CustomColumn::getEloquent('user_name', SystemTableName::USER);
        //         }

        //         System::clearCache();
        //         System::clearRequestSession();
        //     });

        //     $this->showMicrotimeLog('CustomColumn whereFirst' . $count, function() use($count){
        //         for ($i = 0; $i < $count; $i++) {
        //             $custom_table = CustomTable::where('table_name', SystemTableName::USER)->first();
        //             CustomColumn::where('custom_table_id', $custom_table->id)->first();
        //         }

        //         System::clearCache();
        //         System::clearRequestSession();
        //     });
        // }

        $this->assertTrue(true);
    }

    public function testEloquentPermissionPerformance()
    {
        $this->be(LoginUser::find(2)); // user1

        // for($i = 0; $i < 50; $i++){
        //     $items = CustomTable::getEloquent('custom_value_view_all')->getValueModel()->get();

        //     $this->showMicrotimeLog('CustomTable enableEdit' . $items->count(), function() use($items){
        //         $items->each(function($item){
        //             $item->custom_table->enableEdit(true);
        //         });
        //     });
        // }

        // for($i = 0; $i < 50; $i++){
        //     $items = CustomTable::getEloquent('custom_value_view_all')->getValueModel()->get();

        //     $this->showMicrotimeLog('CustomTable hasPermission' . $items->count(), function() use($items){
        //         $items->each(function($item){
        //             $item->custom_table->hasPermission();
        //         });
        //     });
        // }

        // for($i = 0; $i < 50; $i++){
        //     $items = CustomTable::getEloquent('custom_value_view_all')->getValueModel()->get();

        //     $this->showMicrotimeLog('CustomValue enableEdit' . $items->count(), function() use($items){
        //         $items->each(function($item){
        //             $item->enableEdit(true);
        //         });
        //     });
        // }

        // for($i = 0; $i < 50; $i++){
        //     $items = CustomTable::getEloquent('custom_value_view_all')->getValueModel()->get();

        //     $this->showMicrotimeLog('CustomValue enableDelete' . $items->count(), function() use($items){
        //         $items->each(function($item){
        //             $item->enableDelete(true);
        //         });
        //     });
        // }

        // for($i = 0; $i < 50; $i++){
        //     $items = CustomTable::getEloquent('custom_value_view_all')->getValueModel()->get();

        //     $this->showMicrotimeLog('CustomValue lockedWorkflow' . $items->count(), function() use($items){
        //         $items->each(function($item){
        //             $item->lockedWorkflow();
        //         });
        //     });
        // }

        // for($i = 0; $i < 50; $i++){
        //     $items = CustomTable::getEloquent('custom_value_edit_all')->getValueModel()->get();

        //     $this->showMicrotimeLog('CustomValue workflow_status' . $items->count(), function() use($items){
        //         $items->each(function($item){
        //             $item->workflow_status;
        //         });
        //     });
        // }

        $this->assertTrue(true);
    }

    protected function showMicrotimeLog($funcName, $callback)
    {
        $time_start = microtime(true);

        $callback();

        $time = microtime(true) - $time_start;

        $millisecond = $time * 1000;
        \Log::debug("{$funcName} : {$millisecond} millisecond");
    }
}
