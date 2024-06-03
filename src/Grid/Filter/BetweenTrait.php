<?php

namespace Exceedone\Exment\Grid\Filter;

trait BetweenTrait
{
    /**
     * Query closure.
     *
     * @var \Closure
     */
    protected $where;

    /**
     * where null query closure.
     *
     * @var \Closure|null
     */
    protected $whereNull;

    protected function construct(\Closure $query, $label, $column = null)
    {
        $this->where = $query;

        $this->label = $this->formatLabel($label);
        $this->column = $column ?: static::getQueryHash($query, $this->label);
        $this->id = $this->formatId($this->column);

        $this->setupDefaultPresenter();
    }

    /**
     * Set where null query.
     *
     * @param \Closure $whereNull
     * @return $this
     */
    public function whereNull($whereNull)
    {
        $this->whereNull = $whereNull;
        return $this;
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

    /**
     * Get query where null condition from filter.
     *
     * @return array|array[]|mixed|null
     */
    public function whereNullCondition()
    {
        if (!$this->whereNull) {
            return parent::whereNullCondition();
        }

        $this->isnull = true;
        $whereNull = $this->whereNull;
        return $this->buildCondition(function ($query) use ($whereNull) {
            $whereNull($query, $this);
        });
    }
}
