<?php

namespace Exceedone\Exment\Tests;

use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\CustomTable;

trait TestTrait
{
    /**
     * Assert that the response is a superset of the given JSON.
     *
     * @param  array  $data
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
    protected function skipTempTestIfTrue(\Closure $skipMatchFunc, string $messsage = null){
        if($skipMatchFunc()){
            $this->markTestSkipped('This function is temporarily skipped. ' . $messsage);
        }
    }

    /**
     * Initialize all test
     *
     * @return void
     */
    protected function initAllTest(){
        System::clearCache();
        \Exceedone\Exment\Middleware\Morph::defineMorphMap();
    }

    
    /**
     * Check custom value's permission after getting api
     *
     * @param CustomTable $custom_table
     * @param array $ids
     * @param boolean $filterCallback filtering query
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
}
