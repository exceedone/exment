<?php

namespace Exceedone\Exment\Database\Eloquent;

use Exceedone\Exment\Database\ExtendedBuilderTrait;
use Illuminate\Database\Eloquent\Builder;

/**
 * @template TModelClass of \Illuminate\Database\Eloquent\Model
 * @extends Builder<TModelClass>
 */
class ExtendedBuilder extends Builder
{
    use ExtendedBuilderTrait;
}
