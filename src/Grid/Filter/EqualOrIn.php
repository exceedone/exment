<?php

namespace Exceedone\Exment\Grid\Filter;

use Encore\Admin\Grid\Filter\Equal;

class EqualOrIn extends Equal
{
    /**
     * Query for filter.
     *
     * @var string
     */
    protected $query = 'whereOrIn';
}
