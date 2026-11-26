<?php

namespace Exceedone\Exment\Tests\Unit;

use Carbon\Carbon;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\SyncConnection;
use Exceedone\Exment\Model\SyncLink;
use Exceedone\Exment\Model\SyncMapping;
use Exceedone\Exment\Model\SyncRun;
use Exceedone\Exment\Model\SyncRunError;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Encryption\DataKeys;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Services\Sync\ChangesPage;
use Exceedone\Exment\Services\Sync\RemoteField;
use Exceedone\Exment\Services\Sync\RemoteItem;
use Exceedone\Exment\Services\Sync\RemoteObject;
use Exceedone\Exment\Services\Sync\SyncConnector;
use Exceedone\Exment\Services\Sync\SyncConnectors;
use Exceedone\Exment\Services\Sync\SyncEngine;
use Exceedone\Exment\Services\Sync\SyncException;
use Exceedone\Exment\Services\Sync\SyncSecrets;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;

/**
 * A connector of the tests: the items in a static list, read 2 a page.
 */
class FakeSyncConnector implements SyncConnector
{
    /** @var array<int, RemoteItem> */
    public static array $items = [];

    /** @var array<int, string>|null */
    public static ?array $allIds = null;

    public static ?string $fail = null;

    /** @var array<int, string> the secrets the connector was given */
    public static array $secretsSeen = [];

    public function key(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Fake';
    }

    public function settingFields(): array
    {
        return ['space' => 'Space'];
    }

    public function secretFields(): array
    {
        return ['token' => 'Token'];
    }

    public function test(SyncConnection $connection): ?string
    {
        return $connection->secret('token') === 'tok-1' ? null : 'bad token';
    }

    public function objects(SyncConnection $connection): array
    {
        return [new RemoteObject('app1', 'App 1')];
    }

    public function fields(SyncConnection $connection, string $object): array
    {
        return [new RemoteField('title', 'text', 'text', true), new RemoteField('n', 'Number', 'integer'), new RemoteField('owner', 'user', 'user')];
    }

    public function changes(SyncConnection $connection, string $object, ?string $cursor, int $limit): ChangesPage
    {
        static::$secretsSeen[] = strval($connection->secret('token'));
        if (isset(static::$fail)) {
            throw new SyncException(static::$fail);
        }
        $from = intval($cursor);
        $page = array_slice(static::$items, $from, 2);
        $next = $from + count($page);
        return new ChangesPage($page, strval($next), $next >= count(static::$items));
    }

    public function allIds(SyncConnection $connection, string $object): ?array
    {
        return static::$allIds;
    }

    public function attachments(SyncConnection $connection, string $object, RemoteItem $item): array
    {
        return ['note-' . $item->id . '.txt' => 'attached ' . $item->id];
    }

    public function capabilities(string $object): array
    {
        return ['import' => true, 'export' => false];
    }

    public function callsPerMinute(): int
    {
        return 600;
    }
}

/**
 * A connector of the tests that writes its items itself (SyncWriter), with a setting of its own on
 * the mapping (SyncMappingFields): the "prefix" put before each title.
 */
class FakeWriterConnector extends FakeSyncConnector implements \Exceedone\Exment\Services\Sync\SyncWriter, \Exceedone\Exment\Services\Sync\SyncMappingFields
{
    /** @var array<int, string> the items written (not in a dry run) */
    public static array $written = [];

    public function key(): string
    {
        return 'fakew';
    }

    public function mappingFields(SyncConnection $connection, string $object, SyncMapping $mapping): array
    {
        return ['prefix' => ['label' => 'Prefix', 'type' => 'text']];
    }

    public function checkMappingFields(SyncConnection $connection, string $object, array $values): ?string
    {
        return strlen(strval($values['prefix'] ?? '')) > 10 ? 'too long' : null;
    }

    public function write(SyncMapping $mapping, RemoteItem $item, bool $dry): \Exceedone\Exment\Services\Sync\SyncWriteResult
    {
        if (strval($item->values['title'] ?? '') === 'bad') {
            throw new SyncException('the writer refused ' . $item->id);
        }
        if ($dry) {
            return new \Exceedone\Exment\Services\Sync\SyncWriteResult('created');
        }
        $table = $mapping->customTable();
        $record = $table->getValueModel();
        $record->setValue('text', strval(array_get($mapping->options ?? [], 'connector.prefix')) . strval($item->values['title'] ?? ''));
        $record->saved_notify(false);
        $record->save();
        static::$written[] = $item->id;
        return new \Exceedone\Exment\Services\Sync\SyncWriteResult('created', intval($record->id), ['a note of ' . $item->id]);
    }
}

/**
 * The data sync (ISS00113), the engine with a connector of the tests: a run writes the items to
 * records and links them (never twice), a dry run writes nothing, the rules of a change on both
 * sides and of a deleted item, the errors, a failed run, the schedule, the secrets.
 */
class SyncEngineTest extends UnitTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        config(['queue.default' => 'sync', 'exment.data_encryption_keys' => '']);
        DataKeys::reset();
        Cache::flush();
        Features::save([SyncEngine::FEATURE => ['enabled' => true]]);
        SyncConnectors::register(new FakeSyncConnector());
        FakeSyncConnector::$items = [];
        FakeSyncConnector::$allIds = null;
        FakeSyncConnector::$fail = null;
        FakeSyncConnector::$secretsSeen = [];
    }

    protected function tearDown(): void
    {
        SyncConnectors::forget('fake');
        System::clearCache();
        parent::tearDown();
    }

    protected function table(): CustomTable
    {
        /** @var CustomTable $table */
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        return $table;
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function mapping(array $options = [], string $conflict = SyncMapping::CONFLICT_REMOTE, string $delete = SyncMapping::DELETE_MARK): SyncMapping
    {
        $connection = new SyncConnection(['name' => 'C1', 'connector' => 'fake', 'settings' => ['space' => 's1'], 'enabled' => true]);
        $connection->setSecret('token', 'tok-1');
        $connection->save();
        return SyncMapping::query()->create([
            'sync_connection_id' => $connection->id,
            'name' => 'M1',
            'remote_object' => 'app1',
            'custom_table_id' => $this->table()->id,
            'interval_minutes' => 30,
            'conflict_rule' => $conflict,
            'delete_rule' => $delete,
            'field_map' => [['remote' => 'title', 'column' => 'text'], ['remote' => 'n', 'column' => 'integer'], ['remote' => 'owner', 'column' => 'user']],
            'options' => $options + ['check_deletes' => true],
            'enabled' => true,
        ]);
    }

    protected function adminEmail(): string
    {
        /** @var LoginUser $login */
        $login = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        return strval($login->base_user->getValue('email'));
    }

    /**
     * @return array<int, string>
     */
    protected function documentNames(CustomValue $record): array
    {
        $names = [];
        $documents = $record->getDocuments(['count' => 100]);
        foreach ($documents instanceof \Illuminate\Pagination\AbstractPaginator ? $documents->items() : $documents->all() as $document) {
            /** @var CustomValue $document */
            $names[] = strval($document->getValue('document_name'));
        }
        return $names;
    }

    /**
     * @param mixed $n
     */
    protected function item(string $id, string $title, $n = 1, ?string $owner = null, ?string $updated = '2026-11-01 10:00:00', bool $attachments = false): RemoteItem
    {
        return new RemoteItem($id, ['title' => $title, 'n' => $n, 'owner' => $owner], Carbon::parse($updated), Carbon::parse('2026-10-01 09:00:00'), 'https://svc.test/' . $id, $attachments);
    }

    protected function record(SyncMapping $mapping, string $remoteId): ?CustomValue
    {
        $link = SyncLink::query()->where('sync_mapping_id', $mapping->id)->where('remote_id', $remoteId)->first();
        if (!isset($link)) {
            return null;
        }
        System::clearRequestSession();
        $record = $this->table()->getValueModel($link->custom_value_id);
        return $record instanceof CustomValue ? $record : null;
    }

    public function testARunWritesAndLinksTheItems(): void
    {
        FakeSyncConnector::$items = [
            $this->item('r1', 'Sync one', 5, $this->adminEmail()),
            $this->item('r2', 'Sync two', '7'),
            $this->item('r3', 'Sync three', 9, null, '2026-11-02 10:00:00', true),
        ];
        $mapping = $this->mapping(['keep_times' => true]);
        $run = SyncEngine::start($mapping, false, 'manual');
        $run->refresh();

        $this->assertSame(SyncRun::STATUS_SUCCEEDED, $run->status, strval($run->message));
        $this->assertSame(3, $run->count('created'));
        $one = $this->record($mapping, 'r1');
        $this->assertNotNull($one);
        $this->assertSame('Sync one', $one->getValue('text'));
        $this->assertSame(5, intval($one->getValue('integer')));
        $this->assertSame(intval(\Exment::getUserId()), intval(array_get($one->value, 'user')), 'the user found by its mail address');
        $this->assertSame('2026-11-01 10:00:00', $one->updated_at->format('Y-m-d H:i:s'), 'the times of the service kept');
        $three = $this->record($mapping, 'r3');
        $this->assertNotNull($three);
        $this->assertSame(['note-r3.txt'], $this->documentNames($three));

        // written through the save of a row: the required column is in the database
        foreach (['r1' => 'Sync one', 'r2' => 'Sync two', 'r3' => 'Sync three'] as $remoteId => $title) {
            $id = SyncLink::query()->where('sync_mapping_id', $mapping->id)->where('remote_id', $remoteId)->value('custom_value_id');
            $this->assertNotNull($id, $remoteId);
            $json = json_decode(strval(\DB::table(getDBTableName($this->table()))->where('id', $id)->value('value')), true);
            $this->assertSame($title, array_get($json, 'text'), $remoteId . ': the required text');
        }
        $this->assertSame(0, SyncRunError::query()->where('sync_run_id', $run->id)->count());

        $mapping->refresh();
        $this->assertSame('3', $mapping->cursor);
        $this->assertNotNull($mapping->next_run_at);
        $this->assertSame('https://svc.test/r2', SyncLink::query()->where('sync_mapping_id', $mapping->id)->where('remote_id', 'r2')->value('remote_url'));

        // again from the start: the same items, nothing new (never twice)
        $mapping->cursor = null;
        $mapping->save();
        $again = SyncEngine::start($mapping, false, 'manual');
        $again->refresh();
        $this->assertSame(3, $again->count('unchanged'));
        $this->assertSame(3, SyncLink::query()->where('sync_mapping_id', $mapping->id)->count());
        $this->assertSame(['note-r3.txt'], $this->documentNames($three), 'the attachment not added again');
    }

    public function testADryRunWritesNothing(): void
    {
        FakeSyncConnector::$items = [$this->item('d1', 'Dry one'), $this->item('d2', '', 3)];
        $mapping = $this->mapping();
        $before = $this->table()->getValueModel()->query()->count();
        $run = SyncEngine::start($mapping, true, 'manual');
        $run->refresh();

        $this->assertTrue($run->dry_run);
        $this->assertSame(1, $run->count('created'));
        $this->assertSame(1, $run->count('errors'), 'the required title is empty');
        $this->assertSame($before, $this->table()->getValueModel()->query()->count());
        $this->assertSame(0, SyncLink::query()->where('sync_mapping_id', $mapping->id)->count());
        $mapping->refresh();
        $this->assertNull($mapping->cursor, 'a dry run does not move the cursor');
        $this->assertSame('d2', SyncRunError::query()->where('sync_run_id', $run->id)->value('remote_id'));
    }

    public function testAChangeOnBothSides(): void
    {
        FakeSyncConnector::$items = [$this->item('c1', 'Remote v1')];
        $mapping = $this->mapping([], SyncMapping::CONFLICT_LOCAL);
        SyncEngine::start($mapping, false, 'manual');
        $record = $this->record($mapping, 'c1');
        $this->assertNotNull($record);

        // changed in Exment, then in the service
        Carbon::setTestNow(Carbon::now()->addMinutes(5));
        $record->setValue('text', 'Local edit');
        $record->save();
        Carbon::setTestNow();
        FakeSyncConnector::$items = [$this->item('c1', 'Remote v2', 1, null, '2026-11-03 10:00:00')];
        $mapping->refresh();
        $mapping->cursor = null;
        $mapping->save();
        $run = SyncEngine::start($mapping, false, 'manual');
        $run->refresh();
        $this->assertSame(1, $run->count('skipped'), 'Exment wins');
        $local = $this->record($mapping, 'c1');
        $this->assertNotNull($local);
        $this->assertSame('Local edit', $local->getValue('text'));

        // the service wins
        $mapping->conflict_rule = SyncMapping::CONFLICT_REMOTE;
        $mapping->cursor = null;
        $mapping->save();
        SyncEngine::start($mapping, false, 'manual');
        $remote = $this->record($mapping, 'c1');
        $this->assertNotNull($remote);
        $this->assertSame('Remote v2', $remote->getValue('text'));
    }

    public function testDeletedInTheService(): void
    {
        FakeSyncConnector::$items = [$this->item('x1', 'Stays'), $this->item('x2', 'Goes')];
        $mapping = $this->mapping([], SyncMapping::CONFLICT_REMOTE, SyncMapping::DELETE_MARK);
        SyncEngine::start($mapping, false, 'manual');
        $this->assertNotNull($this->record($mapping, 'x2'));

        FakeSyncConnector::$allIds = ['x1'];
        $mapping->refresh();
        $mapping->deletes_checked_at = null;
        $mapping->save();
        $run = SyncEngine::start($mapping, false, 'manual');
        $run->refresh();
        $this->assertSame(1, $run->count('marked'));
        $this->assertNotNull(SyncLink::query()->where('sync_mapping_id', $mapping->id)->where('remote_id', 'x2')->value('remote_deleted_at'));
        $this->assertNotNull($this->record($mapping, 'x2'), 'marked only: the record stays');
    }

    public function testAFailedRunIsABellAndWaitsLonger(): void
    {
        FakeSyncConnector::$fail = 'The service said no (HTTP 503)';
        $mapping = $this->mapping();
        $bells = NotifyNavbar::query()->count();
        $run = SyncEngine::start($mapping, false, 'manual');
        $run->refresh();
        $this->assertSame(SyncRun::STATUS_FAILED, $run->status);
        $this->assertSame('The service said no (HTTP 503)', $run->message);
        $mapping->refresh();
        $this->assertSame(1, $mapping->failures);
        $this->assertTrue($mapping->next_run_at->gt(Carbon::now()->addMinutes(31)), 'a longer wait after a failure');
        $this->assertGreaterThan($bells, NotifyNavbar::query()->count());
    }

    public function testAConnectorThatWritesItself(): void
    {
        SyncConnectors::register(new FakeWriterConnector());
        FakeWriterConnector::$written = [];
        FakeSyncConnector::$items = [$this->item('w1', 'Writer one'), $this->item('w2', 'bad'), $this->item('w3', 'Writer three')];
        $mapping = $this->mapping();
        $mapping->connection->connector = 'fakew';
        $mapping->connection->save();
        $mapping->options = ['connector' => ['prefix' => 'BL-'], 'check_deletes' => false];
        $mapping->save();

        // a dry run: the writer is asked, nothing written
        $dry = SyncEngine::start($mapping, true, 'manual');
        $dry->refresh();
        $this->assertSame(2, $dry->count('created'));
        $this->assertSame(1, $dry->count('errors'));
        $this->assertSame([], FakeWriterConnector::$written);

        $run = SyncEngine::start($mapping->refresh(), false, 'manual');
        $run->refresh();
        $this->assertSame(SyncRun::STATUS_PARTIAL, $run->status);
        $this->assertSame(['w1', 'w3'], FakeWriterConnector::$written);
        $this->assertSame(2, $run->count('created'));
        $this->assertSame(1, $run->count('errors'));
        $this->assertSame('the writer refused w2', SyncRunError::query()->where('sync_run_id', $run->id)->where('remote_id', 'w2')->value('message'));
        $this->assertSame('a note of w1', SyncRunError::query()->where('sync_run_id', $run->id)->where('remote_id', 'w1')->value('message'));
        // the engine links what the writer gives back; the setting of the mapping reached it
        $one = $this->record($mapping, 'w1');
        $this->assertNotNull($one);
        $this->assertSame('BL-Writer one', $one->getValue('text'));

        // again: the same values are not given to the writer again
        $mapping->refresh();
        $mapping->cursor = null;
        $mapping->save();
        $again = SyncEngine::start($mapping, false, 'manual');
        $again->refresh();
        $this->assertSame(2, $again->count('unchanged'));
        $this->assertSame(['w1', 'w3'], FakeWriterConnector::$written);

        $connector = new FakeWriterConnector();
        $this->assertSame('too long', $connector->checkMappingFields($mapping->connection, 'app1', ['prefix' => str_repeat('x', 11)]));
        SyncConnectors::forget('fakew');
    }

    public function testTheMenuOfANewSiteAndOfAnUpdate(): void
    {
        $template = json_decode(strval(file_get_contents(exment_package_path('system_template/config.json'))), true);
        /** @var array<int, array<string, mixed>> $list */
        $list = array_get($template, 'admin_menu', []);
        $menus = collect($list)->keyBy('menu_name');
        $this->assertSame('admin', array_get($menus->get('sync'), 'parent_name'));
        $this->assertSame(intval(array_get($menus->get('scheduled_jobs'), 'order')) + 1, intval(array_get($menus->get('sync'), 'order')));
        $this->assertFileExists(exment_package_path('database/migrations/2026_12_10_000001_add_sync_menu_under_admin.php'));
        $this->assertSame('sync', array_get(Features::core(), SyncEngine::FEATURE . '.menu'));
    }

    public function testTheScheduleAndTheSecrets(): void
    {
        FakeSyncConnector::$items = [$this->item('s1', 'Scheduled')];
        $mapping = $this->mapping();
        $runs = SyncEngine::due();
        $this->assertCount(1, $runs);
        $mapping->refresh();
        $this->assertTrue($mapping->next_run_at->gt(Carbon::now()), 'not due again at once');
        $this->assertCount(0, SyncEngine::due());

        // off: nothing
        Features::save([SyncEngine::FEATURE => ['enabled' => false]]);
        $mapping->next_run_at = null;
        $mapping->save();
        $this->assertCount(0, SyncEngine::due());

        // the secret: encrypted, given decrypted to the connector, never in the json of the model
        /** @var SyncConnection $connection */
        $connection = SyncConnection::query()->find($mapping->sync_connection_id);
        $this->assertContains('tok-1', FakeSyncConnector::$secretsSeen);
        $raw = strval(\DB::table('sync_connections')->where('id', $connection->id)->value('secrets'));
        $this->assertStringNotContainsString('tok-1', $raw);
        $this->assertStringContainsString(SyncSecrets::APP_PREFIX, $raw, 'APP_KEY without DATA_ENCRYPTION_KEYS');
        $this->assertTrue(SyncSecrets::usesAppKey());
        $this->assertStringNotContainsString('tok-1', strval(json_encode($connection->toArray())));
        $this->assertNull((new FakeSyncConnector())->test($connection));

        // with the keys of the encrypted columns: those
        config(['exment.data_encryption_keys' => 'k1:' . base64_encode(str_repeat('a', 32))]);
        DataKeys::reset();
        $this->assertFalse(SyncSecrets::usesAppKey());
        $this->assertStringStartsWith('enc:v1:k1:', SyncSecrets::encrypt('x'));
        $this->assertSame('x', SyncSecrets::decrypt(SyncSecrets::encrypt('x')));
    }
}
