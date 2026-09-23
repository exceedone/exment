<?php

namespace Exceedone\Exment\Model\Traits;

trait CustomViewColumnOptionTrait
{
    /**
     * get sort order.
     */

    // @phpstan-ignore-next-line
    public function getSortOrderAttribute()
    {
        return $this->getOption('sort_order');
    }
    /**
     * set sort order.
     */

    // @phpstan-ignore-next-line
    public function setSortOrderAttribute($sort_order)
    {
        return $this->setOption('sort_order', $sort_order);
    }
    /**
     * get sort type.
     */

    // @phpstan-ignore-next-line
    public function getSortTypeAttribute()
    {
        return $this->getOption('sort_type');
    }
    /**
     * set sort type.
     */

    // @phpstan-ignore-next-line
    public function setSortTypeAttribute($sort_order)
    {
        return $this->setOption('sort_type', $sort_order);
    }

    /**
     * get cell style preset key.
     */

    // @phpstan-ignore-next-line
    public function getGridPresetAttribute()
    {
        return $this->getOption('grid_preset');
    }

    /**
     * set cell style preset key.
     */

    // @phpstan-ignore-next-line
    public function setGridPresetAttribute($grid_preset)
    {
        return $this->setOption('grid_preset', $grid_preset);
    }
}
