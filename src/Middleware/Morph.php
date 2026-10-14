<?php

namespace Exceedone\Exment\Middleware;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Relations\Relation;
use Exceedone\Exment\Model;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\Define;

/**
 * Middleware as Morph.
 * Set Morph info for Eloquent Morph.
 */
class Morph
{
    /**
     * @param \Closure(Request): mixed $next
     * @return mixed
     */
    public function handle(Request $request, \Closure $next)
    {
        // Under mod_php every request starts with an empty in-memory class
        // table, so defineMorphMap() eagerly compiles a Class_{suuid} for every
        // custom table so that Eloquent morph queries can hit any of them.
        // Routes that touch only the table named in their URL can skip this
        // middleware entirely instead - getValueModel() builds that one class
        // JIT - which is what the plugin route middleware_except option is for.
        static::defineMorphMap();
        return $next($request);
    }

    /**
     * define morph map. this called from command.
     *
     * @param bool $lazyClasses when true, populate the alias => class-name map
     *                          but do not eagerly build the Class_{suuid} runtime
     *                          classes; getValueModel() will build the specific
     *                          one that is actually used JIT.
     * @return void
     */
    public static function defineMorphMap($lazyClasses = false)
    {
        // morphMap
        try {
            //if(!canConnection() || !\Schema::hasTable(SystemTableName::CUSTOM_TABLE)){
            if (!canConnection() || !hasTable(SystemTableName::CUSTOM_TABLE)) {
                return;
            }

            $tables = Model\CustomTable::allRecords();

            $morphMaps = static::getMorphs($lazyClasses);

            Relation::morphMap($morphMaps);
        } catch (\Exception $ex) {
            logger($ex);
        }
    }

    /**
     * return Morph maps
     *
     * @param bool $lazyClasses whether to skip eager runtime class creation
     * @return array<string, string>
     */
    public static function getMorphs($lazyClasses = false)
    {
        $tables = Model\CustomTable::allRecords();

        $morphMaps = [
            "table" => Model\CustomTable::class,
            "custom_form_priority" => Model\CustomFormPriority::class,
            "custom_operation" => Model\CustomOperation::class,
            "workflow_condition_header" => Model\WorkflowConditionHeader::class,
            "_custom_view" => Model\CustomView::class,
            "_dashboard" => Model\Dashboard::class,
        ];

        // We already hold every custom table row in $tables, so read table_name
        // and suuid straight from it instead of calling getModelName(name, true)
        // which routes back through CustomTable::allRecordsCache(filter)->first()
        // for each iteration (an N-over-N scan). On this install that switch
        // alone drops the loop from ~24ms to ~1ms for the lazy path.
        $namespace = "Exceedone\\Exment\\Model";
        foreach ($tables as $table) {
            $tableName = $table->table_name;
            $suuid = $table->suuid;
            if (!isset($suuid)) {
                continue;
            }
            $morphMaps[$tableName] = $namespace . "\\Class_" . $suuid;

            if (!$lazyClasses) {
                // Only build the runtime Class_{suuid} eagerly when we cannot
                // rely on JIT (the traditional web flow that may later resolve
                // a stored morph_type string to a class).
                getModelName($tableName);
            }
        }

        return $morphMaps;
    }
}
