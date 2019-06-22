<?php

namespace Exceedone\Exment\Model\Traits;

trait CustomViewColumnOptionTrait
{
    /**
     * get sort order.
     */
    public function getSortOrderAttribute()
    {
        return $this->getOption('sort_order');
    }
    /**
     * set sort order.
     */
    public function setSortOrderAttribute($sort_order)
    {
        return $this->setOption('sort_order', $sort_order);
    }
    /**
     * get sort type.
     */
    public function getSortTypeAttribute()
    {
        return $this->getOption('sort_type');
    }
    /**
     * set sort type.
     */
    public function setSortTypeAttribute($sort_order)
    {
        return $this->setOption('sort_type', $sort_order);
    }

    public function getOption($key, $default = null)
    {
        return $this->getJson('options', $key, $default);
    }
    public function setOption($key, $val = null, $forgetIfNull = false)
    {
        return $this->setJson('options', $key, $val, $forgetIfNull);
    }
}
