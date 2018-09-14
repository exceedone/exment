<?php
namespace Exceedone\Exment\Validator;


class UniqueInTableValidator extends \Illuminate\Validation\Validator
{  /**
  * Validation in table
  *
  * @param $attribute
  * @param $value
  * @param $parameters
  * @return bool
  */
  public function validateUniqueInTable($attribute, $value, $parameters)
  {
      if(count($parameters) < 2){
          return true;
      }

      // get classname for search
      $classname = $parameters[0];
      // get custom_table_id
      $custom_table_id = $parameters[1];

      // get count same value in table;
      $count = $classname::where('custom_table_id', $custom_table_id)
        ->where($attribute, $value)
        ->count();

      if ($count > 0) {
          return false;
      }  

      return true;
  }
}