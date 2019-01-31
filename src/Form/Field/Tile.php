<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Admin;
use Encore\Admin\Form\Field;
use Illuminate\Contracts\Support\Arrayable;

class Tile extends Field
{
    protected $view = 'exment::form.field.tile';

    protected $multipled;

    public function __construct($column, $arguments = array())
    {
        parent::__construct($column, $arguments);

        $this->multiple = false;
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
        if (is_string($options)) {
            return $this->loadRemoteOptions(...func_get_args());
        }

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
        if ($this->options instanceof \Closure) {
            if ($this->form) {
                $this->options = $this->options->bindTo($this->form->model());
            }

            $this->options(call_user_func($this->options, $this->value));
        }

        $this->options = array_filter($this->options);
        $multipled = $this->multipled ? 'true': 'false';

        // template search url
        $template_search_url = admin_base_paths('template', 'search');
        $name = $this->formatName($this->column);
        $script = <<<EOT
    $('#tile-{$this->column} .tile').off('click').on('click', function(event) {
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
            tile.find('.tile-value').val(tile.data('id'));
        }else{
            tile.removeClass('active');
            tile.find('.tile-value').val('');
        }
    });
    
    var template_search_timeout;
    var before = '';
    $('#tile-{$this->column} #template_search').keyup(function(event) {
        var val = $(event.target).val();
        if(val != before){
            before = val;
            clearTimeout(template_search_timeout);
            template_search_timeout = setTimeout(function(){
                searchTemplate($(event.target).val());
            }, 300);
        }
    });

    $(function(){
        searchTemplate(null);
    });

    function searchTemplate(q){
        $('#tile-{$this->column} .overlay').show();
        $.ajax({
            method: 'POST',
            url: '$template_search_url',
            data: {
                q: q,
                name: '{$name}',
                column: '{$this->column}',
                _token:LA.token,
            },
            success: function (data) {
                $('#tile-{$this->column} .tile-group-items').html(data);
                $('#tile-{$this->column} .overlay').hide();
            }
        });
    }
EOT;
        Admin::script($script);

        return parent::render()->with([
            'options'  => $this->options,
        ]);
    }
}
