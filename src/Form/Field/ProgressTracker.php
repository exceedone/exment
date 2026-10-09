<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field;

/**
 * progress tracker
 */
class ProgressTracker extends Field\Display
{
    protected $view = 'exment::form.field.progresstracker';

    // @phpstan-ignore-next-line
    protected static $css = [
        '/vendor/exment/css/progresstracker.css',
    ];

    // @phpstan-ignore-next-line
    protected $steps = null;

    // @phpstan-ignore-next-line
    public function __construct($label)
    {
        $this->label = $label;
        $this->steps = [];
    }

    /**
     * Set options.
     *
     * @param array|callable|string $options
     *
     * @return $this|mixed
     */
    // @phpstan-ignore-next-line
    public function options($options = [])
    {
        if ($options instanceof \Illuminate\Contracts\Support\Arrayable) {
            $options = $options->toArray();
        }

        // @phpstan-ignore-next-line
        foreach ($options as $index => $option) {
            $class = '';
            if (isset($option['active']) && $option['active']) {
                $class = 'active';
            } elseif (isset($option['complete']) && $option['complete']) {
                $class = 'complete';
            };
            $this->options[] = [
                'title' => isset($option['title']) ? $option['title'] : 'Step '.($index + 1),
                'class' => $class,
                'url' => isset($option['url']) ? $option['url'] : '#',
                'description' => isset($option['description']) ? $option['description'] : '',
            ];
        }

        return $this;
    }
    public function render()
    {
        // @phpstan-ignore-next-line
        return parent::render()->with([
            'steps' => $this->options,
        ]);
    }
}
