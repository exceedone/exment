<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field;
use Illuminate\Contracts\Support\Arrayable;

class Tile extends Field
{
    protected $view = 'exment::form.field.tile';

    protected $multipled;

    /**
     * Set overlay loading (for ajax)
     *
     * @var boolean
     */
    protected $overlay = false;

    public function __construct($column, $arguments = array())
    {
        parent::__construct($column, $arguments);

        $this->multipled = false;
    }

    /**
     * Set options.
     *
     * @param array|callable|string $options
     *
     * @return $this|mixed
     */
    public function options($options = [])
    {
        // remote options
        // if (is_string($options)) {
        //     return $this->loadRemoteOptions(...func_get_args());
        // }

        if ($options instanceof Arrayable) {
            $options = $options->toArray();
        }

        if (is_callable($options)) {
            $this->options = $options;
        } else {
            $this->options = (array) $options;
        }

        return $this;
    }

    public function overlay($overlay = true)
    {
        $this->overlay = $overlay;

        return $this;
    }

    public function multiple()
    {
        $this->multipled = true;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function prepare($value)
    {
        //if (is_array($value) && !Arr::isAssoc($value)) {
        //    $value = implode(',', array_filter($value));
        //}

        return $value;
    }
    public function render()
    {
        /** @phpstan-ignore-next-line Instanceof between array and Closure will always evaluate to false. */
        if ($this->options instanceof \Closure) {
            /** @phpstan-ignore-next-line Left side of && is always true and Right side of && is always true */
            if ($this->form && $this->form->model()) {
                $this->options = $this->options->bindTo($this->form->model());
            }

            $this->options(call_user_func($this->options, $this->value));
        }

        $this->options = array_filter($this->options);
        $multipled = $this->multipled ? 'true' : 'false';

        // template search url
        $this->script = <<<EOT

    $(document).on('click.exment_tile', '[data-ajax-link]', {}, function(ev){
        searchTemplate(null, $(ev.target).data('ajax-link'));
    });

    $(document).off('click', '#tile-{$this->column} .tile').on('click', '#tile-{$this->column} .tile', {}, function(event){
        var tile = $(event.target).closest('.tile');
        var hasActive = tile.hasClass('active');

        // not multipled
        if(!{$multipled}){
            var tile_group = $(event.target).closest('.tile-group');
            tile_group.find('.tile').removeClass('active');
            tile_group.find('.tile-value').val('');
        }

        if(!hasActive){
            tile.addClass('active');
            tile.find('.tile-value').val(tile.attr('data-id'));
        }else{
            tile.removeClass('active');
            tile.find('.tile-value').val('');
        }
    });

EOT;

        return parent::render()->with([
            'options'  => $this->options,
            'overlay' => $this->overlay,
        ]);
    }
}
