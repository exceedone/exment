<?php

namespace Exceedone\Exment\Services\Sync;

use Exceedone\Exment\Model\SyncConnection;

/**
 * A connector of the data sync (ISS00113): the part of one service (kintone, Backlog, ...),
 * added by a plugin with SyncConnectors::register(). The engine (SyncEngine) does the rest: the
 * mapping, the links, the runs, the dry run, the schedule.
 *
 * The secrets of a connection (API keys, tokens, passwords) are given decrypted by
 * $connection->secret('name'); a connector never writes one to a log or to an exception message.
 */
interface SyncConnector
{
    /** The key of the connector (as "kintone"): a-z, 0-9 and _. */
    public function key(): string;

    /** The name shown. */
    public function label(): string;

    /**
     * The settings of a connection that are not secret: name => label (a text box each).
     *
     * @return array<string, string>
     */
    public function settingFields(): array;

    /**
     * The secrets of a connection: name => label (kept encrypted, never shown again).
     *
     * @return array<string, string>
     */
    public function secretFields(): array;

    /**
     * Try the connection. Null: it works; else what is wrong (no secret in it).
     */
    public function test(SyncConnection $connection): ?string;

    /**
     * The units of the service a mapping reads from (a kintone app, a Backlog project).
     *
     * @return array<int, RemoteObject>
     */
    public function objects(SyncConnection $connection): array;

    /**
     * The fields of a unit.
     *
     * @return array<int, RemoteField>
     */
    public function fields(SyncConnection $connection, string $object): array;

    /**
     * The items changed since the cursor (null: from the start), at most $limit, oldest first.
     */
    public function changes(SyncConnection $connection, string $object, ?string $cursor, int $limit): ChangesPage;

    /**
     * The ids of all the items of the unit (to find the ones deleted there), null: the service
     * cannot tell (the deleted ones are not looked for).
     *
     * @return array<int, string>|null
     */
    public function allIds(SyncConnection $connection, string $object): ?array;

    /**
     * The attachments of an item, as [name => contents] (read when the item is written).
     *
     * @return array<string, string>
     */
    public function attachments(SyncConnection $connection, string $object, RemoteItem $item): array;

    /**
     * What a unit can do: ['import' => true, 'export' => false] (export is not used in 7.3).
     *
     * @return array<string, bool>
     */
    public function capabilities(string $object): array;

    /**
     * The calls a minute at most (the engine waits between the calls of a run).
     */
    public function callsPerMinute(): int;
}
