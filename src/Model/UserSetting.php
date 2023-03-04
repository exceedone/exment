<?php

namespace Exceedone\Exment\Model;

/**
 * @property mixed $settings
 * @phpstan-consistent-constructor
 */
class UserSetting extends ModelBase
{
    use Traits\DatabaseJsonTrait;
    protected $casts = ['settings' => 'json'];

    public function getSetting($key, $default = null)
    {
        return $this->getJson('settings', $key, $default);
    }
    public function setSetting($key, $val = null, $forgetIfNull = false)
    {
        return $this->setJson('settings', $key, $val, $forgetIfNull);
    }
    public function forgetSetting($key)
    {
        return $this->forgetJson('settings', $key);
    }
    public function clearSetting()
    {
        return $this->clearJson('settings');
    }
}
