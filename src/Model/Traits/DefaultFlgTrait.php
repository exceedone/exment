<?php

namespace Exceedone\Exment\Model\Traits;

trait DefaultFlgTrait
{
    /**
     * set default flg. default flg is only 1 record in 1 table
     */
    protected function setDefaultFlgInTable(){
        // get custom table_id
        $this->setDefaultFlg('custom_table_id');
    }
    
    protected function setDefaultFlg($key){        
        // create query
        $query = static::query();
        // only get default flg is 1
        $query->where('default_flg', true);
        // if has key, filter key and value
        if (isset($key)) {
            // get group key value
            $group_key_value = $this->{$key};
            $query->where($key, $group_key_value);
        }
        // if has id, ignore
        if(isset($this->id)){
            $query->notWhere('id', $this->id);
        }
        // get default flg idlist
        $idlist = $query->pluck('id')->toArray();

        // if count is 0, set default flg this model is 1
        if(count($idlist) == 0){
            $this->default_flg = true;
        }
        // and if this model default flg is 1, set other id's default_flg is 1
        elseif(boolval($this->default_flg)){
            static::whereIn('id', $idlist)->update([
                'default_flg' => false
            ]);
        }
    }
}
