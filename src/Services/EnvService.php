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

        // Read .env-file
        $env = file(path_join(base_path(), '.env'), FILE_IGNORE_NEW_LINES);

        $newEnvs = [];


        // Loop through .env-data
        foreach ($env as $env_value) {

            // Turn the value into an array and stop after the first split
            // So it's not possible to split e.g. the App-Key by accident
            $entry = explode("=", $env_value, 2);

            /** @phpstan-ignore-next-line If condition is always false. */
            if (count($entry) == 0) {
                $newEnvs[] = $entry;
                continue;
            }

            $env_key = $entry[0];

            // find same key
            $hasKey = false;
            foreach ($data as $key => $value) {
                if ($env_key != $key) {
                    continue;
                }

                array_forget($data, $key);
                $hasKey = true;

                if (!$matchRemove) {
                    $newEnvs[] = $key . "=" . static::convertEnvValue($value);
                }
            }
            if (!$hasKey) {
                $newEnvs[] = $env_value;
            }
        }


        // Loop through given data
        foreach ((array)$data as $key => $value) {
            if (array_has($newEnvs, $key)) {
                continue;
            }
            $newEnvs[] = $key . "=" . static::convertEnvValue($value);
        }

        // Turn the array back to an String
        $env = implode("\n", $newEnvs);

        // And overwrite the .env with the new data
        file_put_contents(base_path() . '/.env', $env);
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
        static::setEnv($data, true);
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

        return $lists;
    }
}
