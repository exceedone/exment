<?php

namespace Exceedone\Exment\DataItems\Show;

abstract class ShowBase
{
    protected $custom_table;
    protected $custom_form;
    protected $custom_value;
    protected $modal = false;

    abstract public function __construct($custom_table, $custom_form);

    public static function getItem(...$args)
    {
        list($custom_table, $custom_form) = $args + [null, null, null];

        return new static($custom_table, $custom_form);
    }

    public function custom_value($custom_value)
    {
        $this->custom_value = $custom_value;

        return $this;
    }

    public function id($id)
    {
        $this->custom_value = $this->custom_table->getValueModel($id, boolval(request()->get('trashed')));

        return $this;
    }

    public function modal(bool $modal)
    {
        $this->modal = $modal;

        return $this;
    }

    /**
     * Whether this show is grid.
     *
     * @return bool
     */
    protected function gridShows()
    {
        return false;
    }
}
