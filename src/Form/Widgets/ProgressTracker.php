<?php

namespace Exceedone\Exment\Form\Widgets;

use Illuminate\Contracts\Support\Renderable;

/**
 *
 */
class ProgressTracker implements Renderable
{
    // @phpstan-ignore-next-line
    protected $steps;

    public function __construct()
    {
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
            $this->steps[] = [
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
        return view('exment::widgets.progresstracker')->with([
            'steps' => $this->steps,
        ]);
    }
}
