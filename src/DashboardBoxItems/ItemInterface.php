<?php

namespace Exceedone\Exment\DashboardBoxItems;

interface ItemInterface
{
    /**
     * get header
     */
    // @phpstan-ignore-next-line
    public function header();

    /**
     * get body
     */
    // @phpstan-ignore-next-line
    public function body();

    /**
     * get footer
     */
    // @phpstan-ignore-next-line
    public function footer();

    /**
     * set laravel admin embeds option
     */
    // @phpstan-ignore-next-line
    public static function setAdminOptions(&$form, $dashboard);

    /**
     * saving event
     */
    // @phpstan-ignore-next-line
    public static function saving(&$form);

    /**
     * get item model
     */
    // @phpstan-ignore-next-line
    public static function getItem(...$options);
}
