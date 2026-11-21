<?php

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Features;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * The safety check is a feature of Feature management from 7.2.x.
     *
     * Every site that is there keeps it on (it was on, for everyone): updating
     * does not stop it. Only a new site starts with it off, as every new
     * feature: it is not initialized yet when the install runs this. When
     * that cannot be told, it is kept on (the safe side).
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
        if (static::newSite()) {
            return;
        }
        Features::save([Features::SAFETY_CHECK => ['enabled' => true, 'role_groups' => null, 'menu' => true]]);
    }

    /**
     * The install (exment:install) runs the migrations before the site is
     * initialized (the first administrator is made after).
     */
    protected static function newSite(): bool
    {
        try {
            return !boolval(System::initialized());
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
