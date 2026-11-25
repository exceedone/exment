<?php

namespace Exceedone\Exment\Middleware;

use Illuminate\Http\Request;
use Exceedone\Exment\Enums\EnumBase;
use Illuminate\Console\Scheduling\Schedule;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\QueryLogger;

class ExmentDebug
{
    /**
     * @return mixed
     */
    public function handle(Request $request, \Closure $next)
    {
        static::handleLog($request);

        return $next($request);
    }


    /**
     * @return void
     */
    public static function handleLog(?Request $request = null)
    {
        // Check if database is initialized before accessing System settings
        $loggingToggle = false;
        try {
            $loggingToggle = System::initialized() && System::logging_toggle_available();
        } catch (\Exception $e) {
            // Ignore errors when database is not yet set up
        }

        $debugSql = boolval(config('exment.debugmode', false)) || boolval(config('exment.debugmode_sql', false));
        if ($debugSql || $loggingToggle) {
            static::logDatabase($debugSql, $loggingToggle);
        }

        if (isset($request) && boolval(config('exment.debugmode_request', false))) {
            static::logRequest($request);
        }
    }

    /**
     * @var array<int, mixed>
     */
    protected static $queryLogs = [];

    /**
     * Output log database
     *
     * @param bool $debugSql write the SQL with the values to the application log (the debug settings in .env, for developers)
     * @param bool $performanceLog keep the SQL for the system performance log (without the values)
     * @return void
     */
    protected static function logDatabase($debugSql = true, $performanceLog = false)
    {
        \DB::listen(function ($query) use ($debugSql, $performanceLog) {
            // system performance log: keep "?" as it is, so that no personal or secret data is saved
            if ($performanceLog) {
                QueryLogger::add($query->sql, $query->time);
            }
            if (!$debugSql) {
                return;
            }

            $sql = $query->sql;
            foreach ($query->bindings as $binding) {
                if ($binding instanceof \DateTime) {
                    $binding = $binding->format('Y-m-d H:i:s');
                } elseif ($binding instanceof EnumBase) {
                    $binding = $binding->toString();
                }
                $sql = preg_replace("/\?/", "'{$binding}'", $sql, 1);
            }

            $log_string = "TIME:{$query->time}ms SQL: $sql";
            if (boolval(config('exment.debugmode_sqlfunction', false))) {
                $function = static::getFunctionName();
                $log_string .= ";    function: $function";
            } elseif (boolval(config('exment.debugmode_sqlfunction1', false))) {
                $function = static::getFunctionName(true);
                $log_string .= ";    function: $function";
            }

            exmDebugLog($log_string);
        });
    }



    /**
     * Output log request
     *
     * @param Request $request
     * @return void
     */
    protected static function logRequest($request)
    {
        $hideColumns = LogOperation::getHideColumnsByPath($request->path());
        $data = (array) $request->input();
        // the values only when "keep the input in the logs" is on (ISS02839)
        if (!\Exceedone\Exment\Services\LogManagement\LogPrivacy::storeInput()) {
            $data = \Exceedone\Exment\Services\LogManagement\LogPrivacy::keysOnly($data);
        }
        $input = collect($data)->map(function ($value, $key) use ($hideColumns) {
            if (in_array($key, $hideColumns)) {
                return "$key:xxxx";
            } elseif (is_array($value)) {
                return "$key:" . json_encode($value);
            } else {
                return "$key:$value";
            }
        })->implode(', ');
        $url = $request->fullUrl();
        // never the cookies nor the credentials
        $headerBag = clone $request->headers;
        foreach (['cookie', 'authorization', 'x-api-key', 'x-csrf-token', 'x-xsrf-token', 'php-auth-pw'] as $secret) {
            $headerBag->remove($secret);
        }
        $headers = $headerBag->__toString();
        $ip = $request->ip();

        \Log::debug("\nIP : {$ip}\nURL : $url\nInput : $input\nHeaders --------------------------------------\n$headers");
    }

    /**
     * @param bool $oneFunction
     * @return string
     */
    protected static function getFunctionName($oneFunction = false)
    {
        $bt = debug_backtrace();
        $functions = [];
        $i = 0;
        foreach ($bt as $b) {
            if ($i > 1 && strpos_ex(array_get($b, 'class'), 'Exceedone') !== false) {
                // @phpstan-ignore-next-line
                $functions[] = $b['class'] . '->' . $b['function'] . '.' . array_get($b, 'line');
            }

            if ($oneFunction && count($functions) >= 1) {
                break;
            }

            $i++;
        }
        return implode(" < ", $functions);
    }


    /**
     * Debug log schedule
     *
     * @return void
     */
    public static function logSchedule(Schedule $schedule)
    {
        if (!boolval(config('exment.debugmode_schedule', false))) {
            return;
        }

        $schedule->call(function () {
            \Log::debug('Exment schedule debug everyMinute called.');
        })->everyMinute();

        $schedule->call(function () {
            \Log::debug('Exment schedule debug hourly called.');
        })->hourly();
        \Log::debug('Exment schedule debug defined.');
    }
}
