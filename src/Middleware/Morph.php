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
        static::defineMorphMap();
        return $next($request);
    }

    /**
     * define morph map. this called from command.
     *
     * @return void
     */
    public static function defineMorphMap()
    {
        // morphMap
        try {
            //if(!canConnection() || !\Schema::hasTable(SystemTableName::CUSTOM_TABLE)){
            if (!canConnection() || !hasTable(SystemTableName::CUSTOM_TABLE)) {
                return;
            }

            $tables = Model\CustomTable::allRecords();

            $morphMaps = static::getMorphs();

            Relation::morphMap($morphMaps);
        } catch (\Exception $ex) {
            logger($ex);
        }
    }

    /**
     * return Morph maps
     *
     * @return array<string, string>
     */
    public static function getMorphs()
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
        foreach ($tables as $table) {
            // morphmap
            $table_name = $table->table_name;

            $morphMaps[$table_name] = ltrim(getModelName($table_name, true), "\\");

            // Define Modelname
            getModelName($table_name);
        }

        // @phpstan-ignore-next-line
        return $morphMaps;
    }
}
