<?php

namespace Exceedone\Exment\Model;

/**
 * @property mixed $password
 * @property mixed $login_user_id
 * @property mixed $created_at
 * @property mixed $updated_at
 * @phpstan-consistent-constructor
 */
class PasswordHistory extends ModelBase
{
    protected $guarded = ['id'];

    protected $hidden = ['password'];
}
