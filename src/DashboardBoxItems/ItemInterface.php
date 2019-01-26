<?php

namespace Exceedone\Exment\DashboardBoxItems;

interface ItemInterface 
{
    /**
     * get html
     */
    public function html();

    /**
     * set laravel admin embeds option
     */
    public static function setAdminOptions(&$form);

    /**
     * saving event
     */
    public static function saving(&$form);

    /**
     * get item model
     */
    public static function getItem(...$options);
}
