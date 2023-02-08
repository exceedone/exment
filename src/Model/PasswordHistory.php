<?php

namespace Exceedone\Exment\Model;

/**
 * @phpstan-consistent-constructor
 */
class PasswordHistory extends ModelBase
{
    protected $guarded = ['id'];

    protected $hidden = ['password'];
}
