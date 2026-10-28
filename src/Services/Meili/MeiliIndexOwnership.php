<?php

namespace Exceedone\Exment\Services\Meili;

use Exceedone\Exment\Exceptions\MeiliForeignIndexException;
use Illuminate\Support\Facades\Log;

/**
 * Tie a Meilisearch index to one Exment installation.
 *
 * Nothing in a document says which installation wrote it: the id DocumentMapper
 * builds is "<table_name>__<value_id>", so two installations pointed at the same
 * index - the default name on a shared Meilisearch host is all it takes - share
 * every document whose table name and record id happen to coincide. They then
 * overwrite each other's documents on save, show each other's record content in
 * search results, and delete each other's documents on exment:meili-reconcile.
 *
 * The owner is recorded in an index of its own, "<index>__owner", rather than in
 * the search index: the documents keep exactly the shape they have today (no
 * reindex after an upgrade) and the marker can never surface as a hit or as a
 * table in a facet distribution.
 *
 * An index nobody has claimed is adopted by the first installation that looks,
 * which is how an index built before this guard existed keeps working.
 */
class MeiliIndexOwnership
{
    /** Appended to the search index name to get the marker index name. */
    public const MARKER_SUFFIX = '__owner';

    /** The marker index holds exactly this one document. */
    public const MARKER_ID = 'owner';

    /** How long to wait for Meilisearch to store a freshly written marker. */
    public const WRITE_WAIT_MS = 10000;

    /** @var array<string, true> index name => true once this process has verified or taken ownership. */
    private static array $verified = [];

    /** @var array<string, string> index name => the owner found, for an index this installation does not own. */
    private static array $foreign = [];

    public static function markerIndexName(string $indexName): string
    {
        return $indexName . self::MARKER_SUFFIX;
    }

    /**
     * Stable for the lifetime of an installation, and never the secret itself:
     * APP_KEY is unique per installation and survives deploys and migrations.
     * Falls back to the database name while the key is still unset (install time).
     */
    public static function fingerprint(): string
    {
        $seed = (string) config('app.key');
        if ($seed === '') {
            $default = (string) config('database.default');
            $seed = $default . '|' . (string) config('database.connections.' . $default . '.database');
        }

        return substr(hash('sha256', 'exment-meili-owner|' . $seed), 0, 32);
    }

    /**
     * Verify this installation owns $indexName, adopting an index that no
     * installation has claimed yet.
     *
     * @param  \Meilisearch\Client  $client
     * @throws MeiliForeignIndexException when another installation owns it
     */
    public static function claim($client, string $indexName): void
    {
        if (isset(self::$verified[$indexName])) {
            return;
        }

        $mine = self::fingerprint();

        // Asked again in the same process: answer from what was found, so a
        // foreign index costs neither a round trip nor a second log line.
        if (isset(self::$foreign[$indexName])) {
            throw self::foreignException($indexName, self::$foreign[$indexName], $mine);
        }

        $owner = self::readOwner($client, $indexName);

        if ($owner === null) {
            self::writeOwner($client, $indexName, $mine);
            self::$verified[$indexName] = true;
            return;
        }

        if (!hash_equals($owner, $mine)) {
            self::$foreign[$indexName] = $owner;

            throw self::foreignException($indexName, $owner, $mine);
        }

        self::$verified[$indexName] = true;
    }

    private static function foreignException(string $indexName, string $owner, string $mine): MeiliForeignIndexException
    {
        return new MeiliForeignIndexException(sprintf(
            "Meilisearch index '%s' belongs to another Exment installation (owner %s..., this one %s...)."
            . ' Give this installation an index of its own: set the index name on the system settings'
            . ' screen (MeiliConfig pushes that over MEILISEARCH_INDEX, so editing .env alone does'
            . ' nothing once it has been saved there), then run `php artisan exment:meili-index`.',
            $indexName,
            substr($owner, 0, 8),
            substr($mine, 0, 8)
        ));
    }

    /**
     * The read path's variant: it falls back to MySQL, so a foreign index is an
     * answer ("do not use it") rather than an exception.
     *
     * Anything else - Meilisearch down, a key that may not create an index -
     * leaves ownership simply unknown, and the caller's own error handling deals
     * with it as before.
     *
     * @param  \Meilisearch\Client  $client
     */
    public static function isOwned($client, string $indexName): bool
    {
        try {
            self::claim($client, $indexName);
        } catch (MeiliForeignIndexException $e) {
            Log::error('[Meili] ' . $e->getMessage());

            return false;
        } catch (\Throwable $e) {
            Log::warning("[Meili] could not verify who owns index '{$indexName}': " . $e->getMessage());
        }

        return true;
    }

    /**
     * The fingerprint recorded for $indexName, or null when no installation has
     * claimed it yet.
     *
     * @param  \Meilisearch\Client  $client
     */
    private static function readOwner($client, string $indexName): ?string
    {
        try {
            $doc = (array) $client->index(self::markerIndexName($indexName))->getDocument(self::MARKER_ID);
        } catch (\Throwable $e) {
            if (self::isNotFound($e)) {
                return null;    // no marker index, or no marker document
            }
            throw $e;
        }

        $fingerprint = $doc['fingerprint'] ?? null;

        return is_string($fingerprint) && $fingerprint !== '' ? $fingerprint : null;
    }

    /**
     * @param  \Meilisearch\Client  $client
     */
    private static function writeOwner($client, string $indexName, string $fingerprint): void
    {
        $marker = self::markerIndexName($indexName);

        try {
            $task = $client->createIndex($marker, ['primaryKey' => 'id']);
            $client->waitForTask($task['taskUid'], self::WRITE_WAIT_MS);
        } catch (\Throwable $e) {
            // Already there -> the addDocuments below is all that is needed.
        }

        $task = $client->index($marker)->addDocuments([[
            'id' => self::MARKER_ID,
            'fingerprint' => $fingerprint,
            'claimed_at' => date('c'),
        ]], 'id');
        $client->waitForTask($task['taskUid'], self::WRITE_WAIT_MS);
    }

    /**
     * Whether $e is Meilisearch saying "no such index" or "no such document".
     *
     * The SDK wraps some of those in a plain exception whose message talks about
     * the client version instead, so the whole chain is walked for the real one.
     */
    public static function isNotFound(\Throwable $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if (!($cause instanceof \Meilisearch\Exceptions\ApiException)) {
                continue;
            }
            if ((int) ($cause->httpStatus ?? 0) === 404) {
                return true;
            }
        }

        return false;
    }

    /** Forget what this process has verified (tests, and after a config change). */
    public static function forget(): void
    {
        self::$verified = [];
        self::$foreign = [];
    }
}
