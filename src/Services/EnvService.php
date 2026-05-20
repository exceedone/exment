<?php

namespace Exceedone\Exment\Services;

/**
 *
 */
class EnvService
{
    public static function setEnv($data = [], $matchRemove = false)
    {
        if (empty($data)) {
            return false;
        }

        $path = path_join(base_path(), '.env');
        $env  = file($path, FILE_IGNORE_NEW_LINES);

        $newEnvs    = [];
        $writtenKeys = []; // tracks which keys have already been written to output

        foreach ($env as $line) {
            $parts   = explode('=', $line, 2);
            $lineKey = $parts[0];

            if (!array_key_exists($lineKey, $data)) {
                // Key not in our update set — keep as-is.
                $newEnvs[] = $line;
                continue;
            }

            if ($matchRemove || isset($writtenKeys[$lineKey])) {
                // Removing this key, or already wrote it once — drop this line.
                continue;
            }

            // First occurrence: replace with the new value.
            $newEnvs[] = $lineKey . '=' . static::convertEnvValue($data[$lineKey]);
            $writtenKeys[$lineKey] = true;
        }

        // Append keys not found anywhere in the existing .env.
        if (!$matchRemove) {
            foreach ($data as $key => $value) {
                if (!isset($writtenKeys[$key])) {
                    $newEnvs[] = $key . '=' . static::convertEnvValue($value);
                }
            }
        }

        // Use exclusive lock to avoid concurrent write corruption.
        file_put_contents($path, implode("\n", $newEnvs), LOCK_EX);
    }


    /**
     * Convert env value. If scape or #, append "".
     *
     * @param mixed $value
     * @return string
     */
    protected static function convertEnvValue($value)
    {
        if (strpos($value, '#') !== false || strpos($value, ' ') !== false) {
            if (!preg_match('/".+"/', $value)) {
                return '"' . $value . '"';
            }
        }
        return $value;
    }

    public static function removeEnv($data = [])
    {
        if (empty($data)) {
            return false;
        }

        // Accept a numeric list of key names or an associative array.
        // setEnv() expects an associative array, so normalise here.
        $keys = array_is_list($data) ? array_fill_keys($data, '') : (array) $data;
        static::setEnv($keys, true);
    }

    public static function getEnv($key, $path = null, $matchPrefix = false)
    {
        if (empty($key)) {
            return null;
        }

        if (is_null($path)) {
            $path = path_join(base_path(), '.env');
        }

        if (!\File::exists($path)) {
            return null;
        }

        // Read .env-file
        $env = file($path, FILE_IGNORE_NEW_LINES);

        if (empty($env)) {
            return null;
        }

        // Loop through .env-data
        $lists = [];
        foreach ($env as $env_value) {

            // Turn the value into an array and stop after the first split
            // So it's not possible to split e.g. the App-Key by accident
            $entry = array_map('trim', explode("=", $env_value, 2));

            /** @phpstan-ignore-next-line If condition is always false. */
            if (count($entry) == 0) {
                continue;
            }

            if ($matchPrefix) {
                if (strpos($entry[0], $key) !== 0) {
                    continue;
                }
            } else {
                if ($key != $entry[0]) {
                    continue;
                }
            }

            $lists[] = $entry;
        }

        return empty($lists) ? null : $lists;
    }
}
