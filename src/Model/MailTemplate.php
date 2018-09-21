<?php

namespace Exceedone\Exment\Model;


class MailTemplate extends ModelBase
{
    use \Illuminate\Database\Eloquent\SoftDeletes;
    
    protected $guarded = ['id', 'mail_name'];
}
