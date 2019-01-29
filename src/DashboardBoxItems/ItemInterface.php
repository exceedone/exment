<?php

namespace Exceedone\Exment\DashboardBoxItems;

interface ItemInterface
{
    /**
     * get header
     */
    public function header();

    /**
     * get body
     */
    public function body();

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
