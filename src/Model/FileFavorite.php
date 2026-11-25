<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * File server: a folder or file a user marked as a favorite (ISS00293).
 *
 * @property int $user_id
 * @property int $node_id
 */
class FileFavorite extends Model
{
    protected $guarded = ['id'];
}
