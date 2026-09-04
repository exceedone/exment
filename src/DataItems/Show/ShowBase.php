<?php

namespace Exceedone\Exment\DataItems\Show;

abstract class ShowBase
{
    // @phpstan-ignore-next-line
    protected $custom_table;
    // @phpstan-ignore-next-line
    protected $custom_form;
    // @phpstan-ignore-next-line
    protected $custom_value;
    // @phpstan-ignore-next-line
    protected $modal = false;

    // @phpstan-ignore-next-line
    abstract public function __construct($custom_table, $custom_form);

    // @phpstan-ignore-next-line
    public static function getItem(...$args)
    {
        list($custom_table, $custom_form) = $args + [null, null, null];

        return new static($custom_table, $custom_form);
    }

    // @phpstan-ignore-next-line
    public function custom_value($custom_value)
    {
        $this->custom_value = $custom_value;

        return $this;
    }

    // @phpstan-ignore-next-line
    public function id($id)
    {
        $this->custom_value = $this->custom_table->getValueModel($id, boolval(request()->get('trashed')));

        return $this;
    }

    // @phpstan-ignore-next-line
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
