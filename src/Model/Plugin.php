<?php

namespace Exceedone\Exment\Model;

use DB;

class Plugin extends ModelBase
{
    protected $casts = ['options' => 'json'];

    public static function getFieldById($plugin_id, $field_name)
    {
        return DB::table('plugins')->where('id', $plugin_id)->value($field_name);
    }

    /**
     * Get namespace path
     */
    public function getNameSpace(){
        return namespace_join("App", "Plugins", pascalize($this->plugin_name));
    }

}
