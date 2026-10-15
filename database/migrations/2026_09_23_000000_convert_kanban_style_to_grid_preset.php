<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The kanban card's own appearance vocabulary, said in the one the rest
     * of the product uses.
     *
     * A card column used to name a shape only the board knew how to draw,
     * which meant a value could be orange in the data list and grey on a
     * card, and the setting screen now offers the same preset list every
     * other screen offers. Two vocabularies for one thing is what has to go:
     * whatever is left under the old key would keep painting cards while the
     * setting screen shows an empty box, and nobody could see it, change it
     * or clear it.
     *
     * Three of the old names have no preset of their own, because they were
     * never really their own shape:
     *
     *   chip, state -> a pill. One was a smaller pill, the other a bolder
     *                  one; neither difference survived being put next to
     *                  the real thing.
     *   point       -> a pill as well. Its green was fixed by the board
     *                  rather than chosen by anyone, so the column's own
     *                  colors take over - the icon it carried is kept below.
     *
     * @var array<string, string>
     */
    protected $presets = [
        'text' => 'sys.text_plain',
        'tag' => 'sys.label_tag',
        'pill' => 'sys.status_pill',
        'dot' => 'sys.select_dot',
        'lvl' => 'sys.level_round',
        'state' => 'sys.status_pill',
        'chip' => 'sys.status_pill',
        'point' => 'sys.status_pill',
        'flag' => 'sys.status_badge',
        'avatar' => 'sys.user_avatar',
        'icontext' => 'sys.text_plain',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $rows = DB::table('custom_view_columns')
            ->select(['id', 'options'])
            ->where('options', 'like', '%kanban_style%')
            ->get();

        foreach ($rows as $row) {
            $options = json_decode(strval($row->options), true);
            if (!is_array($options) || !array_key_exists('kanban_style', $options)) {
                continue;
            }

            $style = strval($options['kanban_style']);
            unset($options['kanban_style']);

            // "auto" was the absence of a style, and a preset already chosen
            // by hand is the newer answer of the two: neither is overwritten.
            $preset = isset($this->presets[$style]) ? $this->presets[$style] : null;
            if (isset($preset) && empty($options['grid_preset'])) {
                $options['grid_preset'] = $preset;
            }

            // The board drew this one with a speedometer unless the card
            // named another icon. Written down now that the shape it was
            // attached to is gone.
            if ($style === 'point' && empty($options['kanban_icon'])) {
                $options['kanban_icon'] = 'fa-tachometer';
            }

            DB::table('custom_view_columns')
                ->where('id', $row->id)
                ->update(['options' => json_encode($options)]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * Nothing. Three of the old names share one preset, so what was written
     * cannot say which of them it came from, and the board reads the preset
     * either way - a column left as it is here still draws.
     */
    public function down(): void
    {
    }
};
