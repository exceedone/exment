<?php

namespace Exceedone\Exment\Grid\Filter;

class BetweenDate extends Between
{
    use BetweenTrait;

    /**
     * {@inheritdoc}
     */
    protected $view = 'admin::filter.betweenDatetime';
}
