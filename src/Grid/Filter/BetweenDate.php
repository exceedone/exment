<?php

namespace Exceedone\Exment\Grid\Filter;

class BetweenDate extends Between
{
    use BetweenTrait;

    /**
     * {@inheritdoc}
     * @var string
     */
    protected $view = 'admin::filter.betweenDatetime';
}
