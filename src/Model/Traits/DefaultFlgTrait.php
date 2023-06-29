<?php

namespace Exceedone\Exment\Model\Traits;

/**
 * @method whereIn($column, $values, $boolean = 'and', $not = false)
 */
trait DefaultFlgTrait
{
    /**
     * set default flg. default flg is only 1 record in 1 table
     */
    protected function setDefaultFlgInTable($filterCallback = null, $setCallback = null)
    {
        // get custom table_id
        $this->setDefaultFlg('custom_table_id', $filterCallback);
    }

    protected function setDefaultFlg($key = null, $filterCallback = null, $setCallback = null)
    {
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
        if (isset($this->id)) {
            $query->where('id', '<>', $this->id);
        }

        if (isset($filterCallback)) {
            // get group key value
            $this->{$filterCallback}($query);
        }

        // get default flg idlist
        $idlist = $query->pluck('id')->toArray();

        // if count is 0, set default flg this model is 1
        if (count($idlist) == 0) {
            if (isset($setCallback)) {
                $this->{$setCallback}();
            } else {
                $this->default_flg = true;
            }
        }
        // and if this model default flg is 1, set other id's default_flg is 1
        elseif (boolval($this->default_flg)) {
            static::whereIn('id', $idlist)->update([
                'default_flg' => false
            ]);
        }
    }
}
