<?php

use Exceedone\Exment\Model\Menu;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The system menus the updates of 7.2 added. */
    public const MENUS = ['login_setting', 'api_setting', 'studio', 'feature', 'tuning', 'file_server'];

    /** The languages of Exment. */
    public const LOCALES = ['ja', 'en'];

    /**
     * The updates of 7.2 named the menus they added in the language the
     * update ran with (the command line may have another one than the site).
     * A menu still named so, in another language than the site's, gets its
     * name in the language of the site; a name an administrator gave stays.
     *
     * @return void
     */
    public function up()
    {
        $menuTable = config('admin.database.menu_table', 'admin_menu');
        if (!Schema::hasTable($menuTable)) {
            return;
        }
        $site = Menu::siteLocale();
        foreach (static::MENUS as $name) {
            $title = Menu::systemTitle($name);
            // the site's language has no name for it: nothing is changed
            $siteTitle = trans('exment::exment.menu.system_definitions.' . $name, [], $site);
            if (!is_string($siteTitle) || $siteTitle !== $title) {
                continue;
            }
            $others = [];
            foreach (static::LOCALES as $locale) {
                if ($locale === $site) {
                    continue;
                }
                $other = strval(trans('exment::exment.menu.system_definitions.' . $name, [], $locale));
                if ($other !== $title && !str_starts_with($other, 'exment::')) {
                    $others[] = $other;
                }
            }
            if (count($others) === 0) {
                continue;
            }
            DB::table($menuTable)->where('menu_type', 'system')->where('menu_name', $name)->whereIn('title', $others)->update(['title' => $title]);
        }
    }

    /**
     * @return void
     */
    public function down()
    {
        // the names stay
    }
};
