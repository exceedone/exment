<?php

namespace Exceedone\Exment\Model\Traits;

trait DatabaseJsonOptionTrait
{
    use DatabaseJsonTrait;

    /**
     * Get option value
     * @param string|null $key
     * @param mixed $default
     * @return mixed
     */
    public function getOption($key, $default = null)
    {
        return $this->getJson('options', $key, $default);
    }

    /**
     * Set option value
     * @param string|array<string, mixed>|\Illuminate\Support\Collection<string, mixed> $key
     * @param mixed $val
     * @param bool $forgetIfNull
     * @return $this
     */
    public function setOption($key, $val = null, $forgetIfNull = false)
    {
        return $this->setJson('options', $key, $val, $forgetIfNull);
    }

    /**
     * Forget option value
     * @param string $key
     * @return $this
     */
    public function forgetOption($key)
    {
        return $this->forgetJson('options', $key);
    }

    /**
     * Clear all options
     * @return $this
     */
    public function clearOption()
    {
        return $this->clearJson('options');
    }
}
