<?php

namespace Exceedone\Exment\PartialCrudItems;

/**
 * ProviderBase 
 */
abstract class ProviderBase
{
    protected $custom_table;
    
    public function __construct($custom_table)
    {
        $this->custom_table = $custom_table;
    }

    /**
     * set laravel admin grid's content
     */
    public function setGridContent(&$content)
    {
    }

    /**
     * set laravel admin form's option
     */
    public function setAdminFormOptions(&$form, $id = null)
    {
    }

    /**
     * saving event
     */
    public function saving($form, $id = null)
    {
    }
    
    /**
     * saved event
     */
    public function saved($form, $id)
    {
    }
    
    public static function getItem(...$args)
    {
        list($custom_table) = $args + [null];
        return new static($custom_table);
    }
}
