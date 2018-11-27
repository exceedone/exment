<?php

namespace Exceedone\Exment\Model;

class DashboardBox extends ModelBase
{
    use Traits\AutoSUuidTrait;
    use Traits\DatabaseJsonTrait;
    use \Illuminate\Database\Eloquent\SoftDeletes;
    
    protected $guarded = ['id'];
    protected $casts = ['options' => 'json'];
    
    public function dashboard()
    {
        return $this->belongsTo(Dashboard::class, 'dashboard_id');
    }
    
    public function getOption($key)
    {
        return $this->getJson('options', $key);
    }
    public function setOption($key, $val = null, $forgetIfNull = false)
    {
        return $this->setJson('options', $key, $val, $forgetIfNull);
    }
    public function forgetOption($key)
    {
        return $this->forgetJson('options', $key);
    }
    public function clearOption()
    {
        return $this->clearJson('options');
    }
}
