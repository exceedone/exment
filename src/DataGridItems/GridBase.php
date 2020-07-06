<?php

namespace Exceedone\Exment\DataGridItems;

abstract class GridBase
{
    protected $custom_table;
    protected $custom_view;
    protected $modal = false;

    public static function getItem(...$args)
    {
        list($custom_table, $custom_view) = $args + [null, null];

        return new static($custom_table, $custom_view);
    }

    public function modal(bool $modal){
        $this->modal = $modal;

        return $this;
    }

    public function renderModal($grid){
        return [];
    }
}
