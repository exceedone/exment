<?php

namespace Exceedone\Exment\Form;

use Closure;
use Illuminate\Contracts\Support\Renderable;
use Encore\Admin\Layout\Row;
use Encore\Admin\Layout\Content;

/**
 * For public form content
 */
class PublicContent implements Renderable
{
    /**
     * @var Row[]
     */
    protected $rows = [];

    // @phpstan-ignore-next-line
    protected $header_logo_url;
    // @phpstan-ignore-next-line
    protected $header_label;

    // @phpstan-ignore-next-line
    protected $use_header = true;
    // @phpstan-ignore-next-line
    protected $use_footer = true;

    // @phpstan-ignore-next-line
    protected $background_color = '#FFFFFF';
    // @phpstan-ignore-next-line
    protected $background_color_outer = '#F9FAFC';
    // @phpstan-ignore-next-line
    protected $header_background_color = '#3C8DBC';
    // @phpstan-ignore-next-line
    protected $footer_background_color = '#FFFFFF';
    // @phpstan-ignore-next-line
    protected $header_text_color = '#FFFFFF';
    // @phpstan-ignore-next-line
    protected $footer_text_color = '#000000';
    // @phpstan-ignore-next-line
    protected $container = false;
    // @phpstan-ignore-next-line
    protected $analytics;

    /**
     * Content constructor.
     *
     * @param Closure|null $callback
     */
    public function __construct(\Closure $callback = null)
    {
        if ($callback instanceof Closure) {
            $callback($this);
        }
    }

    /**
     * Set the value of background_color
     *
     * @return  self
     */
    // @phpstan-ignore-next-line
    public function setBackgroundColor($background_color)
    {
        $this->background_color = $background_color;

        return $this;
    }

    /**
     * Set the value of background_color_outer
     *
     * @return  self
     */
    // @phpstan-ignore-next-line
    public function setBackgroundColorOuter($background_color_outer)
    {
        $this->background_color_outer = $background_color_outer;

        return $this;
    }

    /**
     * Set the value of header_background_color
     *
     * @return  self
     */
    // @phpstan-ignore-next-line
    public function setHeaderBackgroundColor($header_background_color)
    {
        $this->header_background_color = $header_background_color;

        return $this;
    }

    /**
     * Set the value of footer_background_color
     *
     * @return  self
     */
    // @phpstan-ignore-next-line
    public function setFooterBackgroundColor($footer_background_color)
    {
        $this->footer_background_color = $footer_background_color;

        return $this;
    }

    /**
     * Set the value of use_header
     *
     * @return  self
     */
    // @phpstan-ignore-next-line
    public function setUseHeader($use_header)
    {
        $this->use_header = $use_header;

        return $this;
    }

    /**
     * Set the value of use_footer
     *
     * @return  self
     */
    // @phpstan-ignore-next-line
    public function setUseFooter($use_footer)
    {
        $this->use_footer = $use_footer;

        return $this;
    }

    /**
     * Set the value of footer_text_color
     *
     * @return  self
     */
    // @phpstan-ignore-next-line
    public function setFooterTextColor($footer_text_color)
    {
        $this->footer_text_color = $footer_text_color;

        return $this;
    }

    /**
     * Set the value of container_fluid
     *
     * @return  self
     */
    public function setIsContainer(bool $container)
    {
        $this->container = $container;

        return $this;
    }

    /**
     * Set the value of header_label
     *
     * @return  self
     */
    // @phpstan-ignore-next-line
    public function setHeaderLabel($header_label)
    {
        $this->header_label = $header_label;

        return $this;
    }


    /**
     * Set the value of header_logo_url
     *
     * @return  self
     */
    // @phpstan-ignore-next-line
    public function setHeaderLogoUrl($header_logo_url)
    {
        $this->header_logo_url = $header_logo_url;

        return $this;
    }

    /**
     * Set analytics
     *
     * @return  self
     */
    // @phpstan-ignore-next-line
    public function setAnalytics($analytics)
    {
        $this->analytics = $analytics;

        return $this;
    }



    /**
     * Alias of method row.
     *
     * @param mixed $content
     *
     * @return $this
     */
    public function body($content)
    {
        return $this->row($content);
    }

    /**
     * Add one row for content body.
     *
     * @param $content
     *
     * @return $this
     */
    // @phpstan-ignore-next-line
    public function row($content)
    {
        if ($content instanceof Closure) {
            $row = new Row();
            call_user_func($content, $row);
            $this->addRow($row);
        } elseif ($content instanceof Row) {
            $this->addRow($content);
        } else {
            $this->addRow(new Row($content));
        }

        return $this;
    }

    /**
     * Render giving view as content body.
     *
     * @param string $view
     * @param array  $data
     *
     * @return $this
     */
    // @phpstan-ignore-next-line
    public function view($view, $data)
    {
        return $this->body(view($view, $data));
    }

    /**
     * Add Row.
     *
     * @param Row $row
     */
    // @phpstan-ignore-next-line
    protected function addRow(Row $row)
    {
        $this->rows[] = $row;
    }

    /**
     * Build html of content.
     *
     * @return string
     */
    public function build()
    {
        ob_start();

        foreach ($this->rows as $row) {
            $row->build();
        }

        $contents = ob_get_contents();

        ob_end_clean();

        // @phpstan-ignore-next-line
        return $contents;
    }

    /**
     * Render this content.
     *
     * @return string
     */
    public function render()
    {
        $items = [
            'content'     => $this->build(),

            'header_text_color' => $this->header_text_color,
            'footer_text_color' => $this->footer_text_color,
            'background_color_outer' => $this->background_color_outer,
            'background_color' => $this->background_color,
            'header_background_color' => $this->header_background_color,
            'footer_background_color' => $this->footer_background_color,

            'container' => $this->container,

            'header_logo_url' => $this->header_logo_url,
            'header_label' => $this->header_label,
            'use_header' => $this->use_header,
            'use_footer' => $this->use_footer,
            'use_footer_label' => !boolval(config('exment.disable_publicform_use_footer_label', false)),
            'analytics' => $this->analytics,

            'container_height' => 40 + ($this->use_header ? 50 : 0) + ($this->use_footer ? 51 : 0),
        ];

        return view('exment::public-form.content', $items)->render();
    }
}
