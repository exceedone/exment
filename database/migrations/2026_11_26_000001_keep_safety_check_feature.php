<?php

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckDefine;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * The safety check is a feature of Feature management from 7.2.x.
     *
     * A site that used it keeps it on: the automatic sending is on, or a
     * safety check was sent. The others (and a new site, where this runs
     * before the safety check tables are made) start with it off, as every
     * new feature; an administrator turns it on in Feature management.
     *
     * @return void
     */
    public function up()
    {
        if (!hasTable(SystemTableName::SYSTEM)) {
            return;
        }
        if (array_key_exists(Features::SAFETY_CHECK, Features::settings())) {
            // decided already
            return;
        }
        if (!static::used()) {
            return;
        }
        Features::save([Features::SAFETY_CHECK => ['enabled' => true, 'role_groups' => null, 'menu' => true]]);
    }

    protected static function used(): bool
    {
        try {
            if (boolval(System::safety_check_auto_enabled())) {
                return true;
            }
            $table = CustomTable::getEloquent(SafetyCheckDefine::TABLE_EVENT);
            return isset($table) && $table->getValueModel()->newQueryWithoutScopes()->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @return void
     */
    public function down()
    {
        // the setting is kept (an administrator may have changed it since)
    }
};
