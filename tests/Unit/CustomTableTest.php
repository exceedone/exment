<?php

namespace Exceedone\Exment\Tests\Unit;


use Exceedone\Exment\Model\CustomTable;

class CustomTableTest extends UnitTestBase
{
    public function testFuncGetMatchedCustomValues1()
    {
        $info = CustomTable::getEloquent('information');

        $keys = [1,3,5];
        $values = $info->getMatchedCustomValues($keys);

        foreach($keys as $key){
            $this->assertTrue(array_has($values, $key));

            $value = array_get($values, $key);
            $this->assertTrue(array_get($value, 'id') == $key);
        }
        
        foreach([2, 4] as $key){
            $this->assertTrue(!array_has($values, $key));
        }
    }
    
    public function testFuncGetMatchedCustomValues2()
    {
        $info = CustomTable::getEloquent('information');

        $keys = ['3'];
        $values = $info->getMatchedCustomValues($keys, 'value.priority');

        foreach($keys as $key){
            $this->assertTrue(array_has($values, $key));

            $value = array_get($values, $key);
            $this->assertTrue(array_get($value, 'value.priority') == $key);
        }
        
        foreach(['2', '4'] as $key){
            $this->assertTrue(!array_has($values, $key));
        }
    }
}
