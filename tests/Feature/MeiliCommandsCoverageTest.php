<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValueModelScope;
use Exceedone\Exment\Model\MeiliDictionary;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Meili\DocumentMapper;
use Exceedone\Exment\Services\Meili\ExmentIndexer;
use Exceedone\Exment\Services\Meili\MeiliClientFactory;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Illuminate\Testing\PendingCommand;
use Symfony\Component\Process\Process;

/**
 * The exment:meili-* commands against a stand-in Meilisearch (tests/tmpfile/fake_meilisearch,
 * served by "php -S"): CI has no Meilisearch server. The stand-in logs every request, so each
 * test checks what the command sent as well as what it printed.
 */
class MeiliCommandsCoverageTest extends FeatureTestBase
{
    use DatabaseTransactions;

    private ?Process $server = null;

    private string $requestLog = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    protected function tearDown(): void
    {
        if ($this->server !== null) {
            $this->server->stop(1);
            $this->server = null;
        }
        if ($this->requestLog !== '' && is_file($this->requestLog)) {
            unlink($this->requestLog);
        }
        System::clearCache();
        parent::tearDown();
    }

    public function testMeiliHealthReportsTheIndexStats(): void
    {
        $host = $this->startFakeMeilisearch([], 7);

        $this->runArtisan('exment:meili-health')
            ->expectsOutputToContain('[Meili] OK - ' . $host)
            ->expectsOutputToContain('Docs  : 7')
            ->assertExitCode(0)
            ->run();

        $this->assertNotEmpty($this->requestsTo('GET', '/health'));
        $this->assertNotEmpty($this->requestsTo('GET', '/indexes/' . $this->indexName() . '/stats'));
    }

    public function testMeiliSettingsShowPrintsTheIndexSettings(): void
    {
        $this->startFakeMeilisearch();

        $this->runArtisan('exment:meili-settings', ['--show' => true])
            ->expectsOutputToContain('fake_searchable_attribute')
            ->assertExitCode(0)
            ->run();

        $this->assertNotEmpty($this->requestsTo('GET', '/indexes/' . $this->indexName() . '/settings'));
        $this->assertEmpty($this->requestsTo('PATCH', '/indexes/' . $this->indexName() . '/settings'));
    }

    public function testMeiliSettingsAppliesTheDictionaryToTheIndex(): void
    {
        $this->startFakeMeilisearch();
        MeiliDictionary::create([
            'type' => MeiliDictionary::TYPE_SYNONYM,
            'word' => 'claudeword',
            'synonyms' => 'claudesynonym',
            'order' => 0,
            'enabled' => 1,
        ]);

        $this->runArtisan('exment:meili-settings')
            ->expectsOutputToContain('Done. searchableAttributes = ')
            ->assertExitCode(0)
            ->run();

        // the last settings update is the command's (saving the entry queued one after commit)
        $patches = $this->requestsTo('PATCH', '/indexes/' . $this->indexName() . '/settings');
        $this->assertNotEmpty($patches);
        $body = $patches[count($patches) - 1]['body'];
        $this->assertIsArray($body);
        $this->assertSame(['claudesynonym'], $body['synonyms']['claudeword'] ?? null);
        $this->assertSame(['claudeword'], $body['synonyms']['claudesynonym'] ?? null);
        // the command waits until Meilisearch has applied the settings
        $this->assertNotEmpty($this->requestsTo('GET', '/tasks/1'));
    }

    public function testMeiliSearchPrintsTheHitsOfTheIndex(): void
    {
        $this->startFakeMeilisearch([
            ['id' => 'claude_fake_table_42', 'table_name' => 'claude_fake_table', 'value_id' => 42, 'label' => 'Claude fake hit'],
        ]);

        $this->runArtisan('exment:meili-search', ['query' => 'claudequery'])
            ->expectsOutputToContain('1 result(s) for "claudequery"')
            ->expectsOutputToContain('Claude fake hit')
            ->assertExitCode(0)
            ->run();

        $searches = $this->requestsTo('POST', '/indexes/' . $this->indexName() . '/search');
        $this->assertCount(1, $searches);
        $this->assertSame('claudequery', $searches[0]['body']['q'] ?? null);
        $this->assertSame(10, $searches[0]['body']['limit'] ?? null);
    }

    public function testMeiliIndexSendsTheRecordsOfEverySearchableTable(): void
    {
        $this->startFakeMeilisearch();
        $tables = $this->indexer()->searchableTables();
        $this->assertNotEmpty($tables, 'the test data has search-enabled tables with freeword columns');
        $expected = $tables->sum(fn (CustomTable $table) => $this->recordCount($table));
        $startedAt = now()->getTimestamp();

        $this->runArtisan('exment:meili-index')
            ->expectsOutputToContain(sprintf('Indexing %d table(s) into "%s"', $tables->count(), $this->indexName()))
            ->expectsOutputToContain("Total: {$expected} documents indexed.")
            ->assertExitCode(0)
            ->run();

        // ensureIndex() creates the index and applies the settings before the documents go out
        $this->assertNotEmpty($this->requestsTo('POST', '/indexes'));
        $this->assertNotEmpty($this->requestsTo('PATCH', '/indexes/' . $this->indexName() . '/settings'));
        $sent = collect($this->requestsTo('POST', '/indexes/' . $this->indexName() . '/documents'))
            ->sum(fn (array $request) => is_array($request['body']) ? count($request['body']) : 0);
        $this->assertSame($expected, $sent);

        // and the index is marked as built (ExmentIndexer::markIndexBuilt())
        System::clearCache();
        $mark = (array) System::meili_indexed_at();
        $this->assertSame($this->indexName(), $mark['index'] ?? null);
        $this->assertGreaterThanOrEqual($startedAt, (int) ($mark['at'] ?? 0));
    }

    public function testMeiliReconcileDryRunCountsTheRecordsMissingFromTheIndex(): void
    {
        $this->startFakeMeilisearch();
        $table = $this->indexer()->searchableTables()
            ->first(fn (CustomTable $table) => $this->recordCount($table) > 0);
        $this->assertNotNull($table, 'the test data has a search-enabled table with records');
        $count = $this->recordCount($table);

        // the stand-in index holds no document: every record of the table is missing
        $this->runArtisan('exment:meili-reconcile', ['--dry-run' => true, '--table' => $table->table_name])
            ->expectsOutputToContain("Reconcile done. found: {$count} missing, 0 outdated, 0 orphan, 0 document(s) of de-indexed tables.")
            ->assertExitCode(0)
            ->run();

        $fetches = $this->requestsTo('POST', '/indexes/' . $this->indexName() . '/documents/fetch');
        $this->assertNotEmpty($fetches);
        $this->assertStringContainsString($table->table_name, (string) json_encode($fetches[0]['body']['filter'] ?? null));
        // a dry run writes nothing
        $this->assertEmpty($this->requestsTo('POST', '/indexes/' . $this->indexName() . '/documents'));
        $this->assertEmpty($this->requestsTo('POST', '/indexes/' . $this->indexName() . '/documents/delete-batch'));
    }

    public function testMeiliCommandsStopWhenMeilisearchCannotBeReached(): void
    {
        // nothing listens on port 1
        config(['meilisearch.host' => 'http://127.0.0.1:1']);

        $this->runArtisan('exment:meili-search', ['query' => 'claudequery'])
            ->expectsOutputToContain('Could not connect to Meilisearch (http://127.0.0.1:1)')
            ->assertExitCode(1)
            ->run();
        $this->runArtisan('exment:meili-health')
            ->expectsOutputToContain('[Meili] Could NOT connect to http://127.0.0.1:1')
            ->assertExitCode(1)
            ->run();
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function runArtisan(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        // artisan() only answers an int when console output mocking is off
        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }

    /**
     * Serve the stand-in on a free port and point the meilisearch config at it.
     *
     * @param array<int, array<string, mixed>> $hits the search hits it answers
     * @return string the host
     */
    private function startFakeMeilisearch(array $hits = [], int $documents = 0): string
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            $this->fail("no free port: {$errstr}");
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $this->requestLog = (string) tempnam(sys_get_temp_dir(), 'fake_meilisearch');
        $this->server = new Process(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", dirname(__DIR__) . '/tmpfile/fake_meilisearch/router.php'],
            null,
            [
                'FAKE_MEILI_LOG' => $this->requestLog,
                'FAKE_MEILI_HITS' => (string) json_encode($hits),
                'FAKE_MEILI_DOCS' => (string) $documents,
            ]
        );
        $this->server->start();

        $ready = false;
        for ($i = 0; $i < 50 && !$ready; $i++) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($connection !== false) {
                fclose($connection);
                $ready = true;
            } else {
                usleep(100000);
            }
        }
        $this->assertTrue($ready, 'the stand-in Meilisearch did not start: ' . $this->server->getErrorOutput());

        $host = "http://127.0.0.1:{$port}";
        config(['meilisearch.host' => $host, 'meilisearch.key' => null]);

        return $host;
    }

    /**
     * The requests the stand-in received with this method and path, oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function requestsTo(string $method, string $path): array
    {
        $lines = file($this->requestLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return collect($lines === false ? [] : $lines)
            ->map(fn (string $line) => json_decode($line, true))
            ->filter(fn ($request) => is_array($request) && ($request['method'] ?? null) === $method && ($request['path'] ?? null) === $path)
            ->values()
            ->all();
    }

    private function indexName(): string
    {
        return (string) config('meilisearch.index');
    }

    private function indexer(): ExmentIndexer
    {
        return new ExmentIndexer(MeiliClientFactory::make(), new DocumentMapper(), $this->indexName(), (int) config('meilisearch.batch_size', 1000));
    }

    /**
     * The records the indexer and reconcile read: soft-deleted ones excluded, no permission filter.
     */
    private function recordCount(CustomTable $table): int
    {
        return getModelName($table)::query()
            ->withoutGlobalScope(CustomValueModelScope::class)
            ->count();
    }
}
