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

    protected $header_logo_url;
    protected $header_label;

    protected $use_header = true;
    protected $use_footer = true;

    protected $background_color = '#FFFFFF';
    protected $background_color_outer = '#F9FAFC';
    protected $header_background_color = '#3C8DBC';
    protected $footer_background_color = '#FFFFFF';
    protected $header_text_color = '#FFFFFF';
    protected $footer_text_color = '#000000';
    protected $container = false;
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
    public function view($view, $data)
    {
        return $this->body(view($view, $data));
    }

    /**
     * Add Row.
     *
     * @param Row $row
     */
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
