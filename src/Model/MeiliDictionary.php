<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Jobs\ApplyMeiliSettingsJob;

/**
 * Admin-managed relevance dictionary (synonyms + stop words) for Meilisearch.
 *
 * These are index SETTINGS, so saving/deleting applies via exment:meili-settings
 * WITHOUT reindexing documents (fast). Merged on top of the config-file
 * synonyms/stop_words in IndexSettings.
 *
 * @property mixed $type
 * @property mixed $word
 * @property mixed $synonyms
 */
class MeiliDictionary extends ModelBase
{
    use Traits\UseRequestSessionTrait;

    public const TYPE_SYNONYM = 'synonym';
    public const TYPE_STOPWORD = 'stopword';

    protected $fillable = ['type', 'word', 'synonyms', 'order', 'enabled'];

    protected $casts = ['enabled' => 'boolean', 'order' => 'integer'];

    protected static function boot()
    {
        parent::boot();

        // Changing the dictionary re-applies the index settings (fast, no reindex).
        static::saved(function ($model) {
            $model->dispatchApply();
        });
        static::deleted(function ($model) {
            $model->dispatchApply();
        });
    }

    protected function dispatchApply(): void
    {
        try {
            if (class_exists(\Meilisearch\Client::class)) {
                ApplyMeiliSettingsJob::dispatch();
            }
        } catch (\Throwable $e) {
            // queue/meili not configured -> ignore.
        }
    }

    /**
     * Split a user-entered term list (comma / newline / full-width comma separated)
     * into a clean array of terms.
     *
     * @return array<int,string>
     */
    public static function splitTerms(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        $parts = preg_split('/[,\x{3001}\x{FF0C}\r\n]+/u', $raw) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn ($t) => $t !== ''));
    }

    /**
     * [Pure] Build the Meili synonyms map from dictionary rows.
     * Each row's [word] + [synonyms] form ONE mutual group: every term maps to
     * all the others. Maps are merged (unique) when a term appears in several groups.
     *
     * @param array<int,array{word:string,synonyms:?string}> $rows
     * @return array<string,array<int,string>>
     */
    public static function buildSynonymsMap(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $group = array_values(array_unique(array_merge(
                [trim($row['word'])],
                self::splitTerms($row['synonyms'] ?? null)
            )));
            $group = array_values(array_filter($group, fn ($t) => $t !== ''));
            if (count($group) < 2) {
                continue; // a synonym needs at least 2 terms.
            }
            foreach ($group as $term) {
                $others = array_values(array_diff($group, [$term]));
                $map[$term] = array_values(array_unique(array_merge($map[$term] ?? [], $others)));
            }
        }

        return $map;
    }

    /**
     * [Pure] Build the flat stop-word list from dictionary rows.
     *
     * @param array<int,array{word:string}> $rows
     * @return array<int,string>
     */
    public static function buildStopWords(array $rows): array
    {
        $words = [];
        foreach ($rows as $row) {
            foreach (self::splitTerms($row['word']) as $w) {
                $words[] = $w;
            }
        }

        return array_values(array_unique($words));
    }

    /**
     * Merge the DB dictionary (synonyms + stop words) into the settings opts
     * passed to IndexSettings::build. Config-file entries are kept and extended.
     *
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public static function mergeIntoOpts(array $opts): array
    {
        try {
            $rows = static::where('enabled', 1)->orderBy('order')->get();
        } catch (\Throwable $e) {
            return $opts; // table not migrated yet.
        }

        $synRows = $rows->where('type', self::TYPE_SYNONYM)
            ->map(fn ($r) => ['word' => (string) $r->word, 'synonyms' => $r->synonyms])->all();
        $stopRows = $rows->where('type', self::TYPE_STOPWORD)
            ->map(fn ($r) => ['word' => (string) $r->word])->all();

        // Synonyms: merge config map + DB map.
        $synonyms = (array) ($opts['synonyms'] ?? []);
        foreach (self::buildSynonymsMap($synRows) as $term => $others) {
            $synonyms[$term] = array_values(array_unique(array_merge($synonyms[$term] ?? [], $others)));
        }
        if (!empty($synonyms)) {
            $opts['synonyms'] = $synonyms;
        }

        // Stop words: merge config list + DB list.
        $opts['stop_words'] = array_values(array_unique(array_merge(
            (array) ($opts['stop_words'] ?? []),
            self::buildStopWords($stopRows)
        )));

        return $opts;
    }
}
