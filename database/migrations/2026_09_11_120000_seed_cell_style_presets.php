<?php

use Illuminate\Database\Migrations\Migration;
use Exceedone\Exment\Model\CellStylePreset;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Turns the shipped preset catalog into rows, so every installation has
     * the full library the moment it migrates - and manages it on the
     * setting screen like any preset made by hand. Insert-if-missing: a row
     * an administrator has renamed or repainted since is left alone.
     */
    public function up(): void
    {
        CellStylePreset::seedDefaults();
    }

    /**
     * Reverse the migrations.
     *
     * Only the seeded keys are removed; presets people made stay.
     */
    public function down(): void
    {
        if (!\Schema::hasTable('cell_style_presets')) {
            return;
        }

        CellStylePreset::where('suuid', 'like', CellStylePreset::BUILTIN_PREFIX . '%')->delete();
    }
};
