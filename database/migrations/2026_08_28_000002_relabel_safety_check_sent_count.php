<?php

use Exceedone\Exment\Services\SafetyCheck\SafetyCheckInstaller;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * One-shot relabel of safety_check_event.sent_count, moved OUT of ensureAll().
     *
     * ensureAll() runs on every migrate / exment:update, so keeping the relabel
     * in it meant two bugs: a label the admin renamed on the UI was silently
     * reverted on the next update, and because the new value comes from
     * exmtrans(), the label flipped back and forth whenever the CLI's APP_LOCALE
     * differed from the locale the environment was installed with. Running it
     * once from a dated migration is the correct shape for a data patch.
     */
    public function up(): void
    {
        SafetyCheckInstaller::ensureAll();
        SafetyCheckInstaller::ensureSentCountLabel();
    }

    public function down(): void
    {
        // The label is display-only; reverting to the old LINE-only wording
        // would be a regression, not a rollback.
    }
};
