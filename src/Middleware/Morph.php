<?php

namespace Exceedone\Exment\Middleware;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Relations\Relation;
use Exceedone\Exment\Model;
use Exceedone\Exment\Model\Define;

/**
 * Middleware as Morph.
 * Set Morph info for Eloquent Morph.
 */
class Morph
{
    public function handle(Request $request, \Closure $next)
    {
        static::defineMorphMap();
        return $next($request);
    }

    /**
     * define morph map. this called from command.
     *
     */
    public static function defineMorphMap()
    {
        // morphMap
        try {
            $tables = Model\CustomTable::allRecords();
                
            $morphMaps = [
                "table" => Model\CustomTable::class,
            ];
            foreach ($tables as $table) {
                // morphmap
                $table_name = $table->table_name;

                $morphMaps[$table_name] = ltrim(getModelName($table_name, true), "\\");

                // Define Modelname
                getModelName($table_name);
            }
            Relation::morphMap($morphMaps);
        } catch (\Exception $ex) {
            logger($ex);
        }
    }
}
