<?php

namespace Exceedone\Exment\Grid\Filter;

use Illuminate\Support\Arr;
use Encore\Admin\Grid\Filter\Between;

trait BetweenDateTrait
{
    protected function construct(\Closure $query, $label, $column = null)
    {
        $this->where = $query;

        $this->label = $this->formatLabel($label);
        $this->column = $column ?: static::getQueryHash($query, $this->label);
        $this->id = $this->formatId($this->column);

        $this->setupDefaultPresenter();
    }
    
    /**
     * Get the hash string of query closure.
     *
     * @param \Closure $closure
     * @param string   $label
     *
     * @return string
     */
    public static function getQueryHash(\Closure $closure, $label = '')
    {
        $reflection = new \ReflectionFunction($closure);

        return md5($reflection->getFileName().$reflection->getStartLine().$reflection->getEndLine().$label);
    }
}
