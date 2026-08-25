<?php

use Exceedone\Exment\Services\SafetyCheck\SafetyCheckInstaller;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Mail fallback for the safety check: environments installed before this
     * need the safety_check_mail template seeded and the "mail" option added
     * to safety_check_answer.channel. ensureAll() is idempotent.
     */
    public function up(): void
    {
        SafetyCheckInstaller::ensureAll();
    }

    public function down(): void
    {
        // The template row and the extra select option are harmless to keep;
        // full teardown is SafetyCheckInstaller::removeAll() via the install migration.
    }
};
