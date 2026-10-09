<?php

namespace Exceedone\Exment\PartialCrudItems;

/**
 * ProviderBase
 * @phpstan-consistent-constructor
 */
abstract class ProviderBase
{
    // @phpstan-ignore-next-line
    protected $custom_table;

    // @phpstan-ignore-next-line
    public function __construct($custom_table)
    {
        $this->custom_table = $custom_table;
    }

    /**
     * set laravel admin grid's content
     */
    // @phpstan-ignore-next-line
    public function setGridContent(&$content)
    {
    }

    /**
     * set laravel admin row action
     */
    // @phpstan-ignore-next-line
    public function setGridRowAction(&$actions)
    {
    }

    /**
     * set laravel admin form's option
     */
    // @phpstan-ignore-next-line
    public function setAdminFormOptions(&$form, $id = null)
    {
    }

    /**
     * set laravel admin form's tool
     */
    // @phpstan-ignore-next-line
    public function setAdminFormTools(&$tools, $id = null)
    {
    }

    /**
     * set laravel admin show form's tool
     */
    // @phpstan-ignore-next-line
    public function setAdminShowTools(&$tools, $id = null)
    {
    }

    /**
     * saving event
     */
    // @phpstan-ignore-next-line
    public function saving($form, $id = null)
    {
    }

    /**
     * saved event
     */
    // @phpstan-ignore-next-line
    public function saved($form, $id)
    {
    }

    // @phpstan-ignore-next-line
    public static function getItem(...$args)
    {
        list($custom_table) = $args + [null];
        return new static($custom_table);
    }
}
