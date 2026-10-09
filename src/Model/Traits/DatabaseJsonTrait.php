<?php

namespace Exceedone\Exment\Model\Traits;

trait DatabaseJsonTrait
{
    /**
     * get value from json
     * @param string $dbcolumnname
     * @param string|null $key
     * @param mixed $default
     * @return mixed
     */
    protected function getJson($dbcolumnname, $key, $default = null)
    {
        $json = $this->{$dbcolumnname};
        if (!isset($json)) {
            return $default;
        }
        return array_get($json, $key, $default);
    }

    /**
     * set value from json
     * @param string $dbcolumnname
     * @param string|array<string, mixed>|\Illuminate\Support\Collection<string, mixed> $key
     * @param mixed $val
     * @param bool $forgetIfNull
     * @return $this
     */
    protected function setJson($dbcolumnname, $key, $val = null, $forgetIfNull = false)
    {

        // @phpstan-ignore-next-line
        if (!isset($key)) {
            return $this;
        }
        // if key is array, loop key value
        if (is_array($key) || $key instanceof \Illuminate\Support\Collection) {
            foreach ($key as $k => $v) {
                $this->setJson($dbcolumnname, $k, $v);
            }
            return $this;
        }

        // if $val is null and $forgetIfNull is true, forget value
        if ($forgetIfNull && is_null($val)) {
            return $this->forgetJson($dbcolumnname, $key);
        }

        $value = $this->{$dbcolumnname};
        if (is_null($value)) {
            $value = [];
        }
        $value[$key] = $this->convertSetValue($val);
        $this->{$dbcolumnname} = $value;

        return $this;
    }

    /**
     * forget value from json
     * @param string $dbcolumnname
     * @param string $key
     * @return $this
     */
    protected function forgetJson($dbcolumnname, $key)
    {

        // @phpstan-ignore-next-line
        if (!isset($key)) {
            return $this;
        }

        $value = $this->{$dbcolumnname};
        if (is_null($value)) {
            $value = [];
        }
        array_forget($value, $key);
        $this->{$dbcolumnname} = $value;

        return $this;
    }
    /**
     * clear value from json
     * @param string $dbcolumnname
     * @return $this
     */
    protected function clearJson($dbcolumnname)
    {
        $this->{$dbcolumnname} = [];
        return $this;
    }

    /**
     * re-set field data
     * if user update form and save, but other field remove if not conatins form field, so re-set field before update
     * @param string $dbcolumnname
     * @return void
     */
    protected function prepareJson($dbcolumnname)
    {
        ///// saving event for image, file event
        $value = $this->{$dbcolumnname} ?? [];
        $original = json_decode_ex($this->getRawOriginal($dbcolumnname), true) ?? [];

        // loop columns
        $update_flg = false;

        // @phpstan-ignore-next-line
        foreach ($original as $key => $o) {

            // @phpstan-ignore-next-line
            if ($this->setAgainOriginalValue($value, $original, $key)) {
                $update_flg = true;
            }
        }

        // array_forget if $v is null
        // if not execute this, mysql column "virtual" returns string "null".
        foreach ($value as $k => $v) {
            if (is_null($v)) {
                $update_flg = true;
                array_forget($value, $k);
            }
        }

        // if update
        if ($update_flg) {
            $this->setAttribute($dbcolumnname, $value);
        }
    }

    /**
     * whether setting original data. and return setting or not.
     * @param array<string, mixed>|null $value
     * @param array<string, mixed> $original
     * @param string $key
     * @return bool
     */
    protected function setAgainOriginalValue(&$value, $original, $key)
    {
        if (is_null($value)) {
            $value = [];
        }
        // if not key, set from original
        if (array_key_exists($key, $value)) {
            return false;
        }

        if (!array_key_value_exists($key, $original)) {
            return false;
        }

        $value[$key] = array_get($original, $key);
        return true;
    }

    /**
     * Convert set value.
     * If carbon, return this array.
     * [
     *     date:"2020-07-01 00:00:00.000000"
     *     timezone_type:3
     *     timezone:"Asia/Tokyo"
     * ]
     * Because PHP7.4, execute setvalue, carbon is empty array. So before setting array.
     *
     * @param mixed $value
     * @return mixed
     */
    protected function convertSetValue($value)
    {
        if ($value instanceof \Carbon\Carbon) {

            return \Exment::carbonToArray($value);
        }

        return $value;
    }
}
