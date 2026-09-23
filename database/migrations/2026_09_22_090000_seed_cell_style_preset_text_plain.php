<?php

use Illuminate\Database\Migrations\Migration;
use Exceedone\Exment\Model\CellStylePreset;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds the presets shipped since the catalog was first seeded. The
     * seeding method inserts only what is missing, so this cannot touch a
     * preset an administrator has renamed or repainted - and an installation
     * created after the new entry landed simply finds nothing to do.
     *
     * A kanban card needs this one: with no style a card prints the column
     * name in front of every value, and "the value alone" had no preset to
     * ask for until now.
     */
    public function up(): void
    {
        CellStylePreset::seedDefaults();
    }

    /**
     * Reverse the migrations.
     *
     * Nothing: the previous migration owns every seeded key, and removing
     * them here would strip the whole catalog from an installation that is
     * only stepping back one version.
     */
    public function down(): void
    {
    }
};
