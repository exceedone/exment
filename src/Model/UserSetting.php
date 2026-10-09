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


    // @phpstan-ignore-next-line
    public function getSetting($key, $default = null)
    {
        return $this->getJson('settings', $key, $default);
    }

    // @phpstan-ignore-next-line
    public function setSetting($key, $val = null, $forgetIfNull = false)
    {
        return $this->setJson('settings', $key, $val, $forgetIfNull);
    }

    // @phpstan-ignore-next-line
    public function forgetSetting($key)
    {
        return $this->forgetJson('settings', $key);
    }

    // @phpstan-ignore-next-line
    public function clearSetting()
    {
        return $this->clearJson('settings');
    }
}
