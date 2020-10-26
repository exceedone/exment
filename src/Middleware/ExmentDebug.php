<?php

namespace Exceedone\Exment\Middleware;

use Illuminate\Http\Request;
use Exceedone\Exment\Enums\EnumBase;

class ExmentDebug
{
    public function handle(Request $request, \Closure $next)
    {
        if (boolval(config('exment.debugmode', false)) || boolval(config('exment.debugmode_sql', false))) {
            $this->logDatabase();
        }

        if (boolval(config('exment.debugmode_request', false))) {
            $this->logRequest($request);
        }

        return $next($request);
    }

    
    /**
     * Output log database
     *
     * @return void
     */
    protected function logDatabase()
    {
        \DB::listen(function ($query) {
            $sql = $query->sql;
            foreach ($query->bindings as $binding) {
                if ($binding instanceof \DateTime) {
                    $binding = $binding->format('Y-m-d H:i:s');
                } elseif ($binding instanceof EnumBase) {
                    $binding = $binding->toString();
                }
                $sql = preg_replace("/\?/", "'{$binding}'", $sql, 1);
            }

            $log_string = "TIME:{$query->time}ms;    SQL: $sql";
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
     * @return void
     */
    protected function logRequest($request)
    {
        $input = collect($request->input())->map(function ($value, $key) {
            if (in_array($key, LogOperation::getHideColumns())) {
                return "$key:xxxx";
            } else {
                return "$key:$value";
            }
        })->implode(', ');
        $url = $request->fullUrl();
        $headers = $request->headers->__toString();

        \Log::debug("URL : $url\nInput : $input\nHeaders --------------------------------------\n$headers");
    }

    protected static function getFunctionName($oneFunction = false)
    {
        $bt = debug_backtrace();
        $functions = [];
        $i = 0;
        foreach ($bt as $b) {
            if ($i > 1 && strpos(array_get($b, 'class'), 'Exceedone') !== false) {
                $functions[] = $b['class'] . '->' . $b['function'] . '.' . array_get($b, 'line');
            }

            if ($oneFunction && count($functions) >= 1) {
                break;
            }

            $i++;
        }
        return implode(" < ", $functions);
    }
}
