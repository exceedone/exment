<?php

namespace Exceedone\Exment\DataGridItems;


abstract class GridBase
{
    protected $custom_table;
    protected $custom_view;

    public static function getItem(...$args)
    {
        list($custom_table, $custom_view) = $args + [null, null];

        return new static($custom_table, $custom_view);
    }
}
