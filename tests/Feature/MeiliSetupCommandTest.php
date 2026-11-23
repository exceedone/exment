<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Console\MeiliSetupCommand;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Illuminate\Testing\PendingCommand;
use Symfony\Component\Process\Process;

/**
 * exment:meili-setup against the stand-in Meilisearch (tests/tmpfile/fake_meilisearch).
 * --skip-env everywhere: the tests must not write the .env of the test app.
 */
class MeiliSetupCommandTest extends FeatureTestBase
{
    use DatabaseTransactions;

    private ?Process $server = null;

    private string $requestLog = '';

    private string|false $envKey = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->envKey = getenv('MEILI_MASTER_KEY');
        putenv('MEILI_MASTER_KEY');
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
        if ($this->envKey !== false) {
            putenv('MEILI_MASTER_KEY=' . $this->envKey);
        }
        System::clearCache();
        parent::tearDown();
    }

    public function testSavesTheSettingsAndBuildsTheIndex(): void
    {
        $host = $this->startFakeMeilisearch();

        $this->runArtisan('exment:meili-setup', ['--host' => $host, '--key' => 'setup-test-key-0123456789', '--skip-env' => true])
            ->expectsOutputToContain('OK, version 1.10.0')
            ->expectsOutputToContain('[5/5] Index')
            ->expectsOutputToContain('Global search is on.')
            ->assertExitCode(0)
            ->run();

        System::clearCache();
        $this->assertSame($host, System::meili_host());
        $this->assertSame('setup-test-key-0123456789', System::meili_key());
        $this->assertTrue(System::meili_realtime_sync());
        $this->assertTrue(System::meili_repair_enabled());
        $this->assertTrue(System::meili_global_search());
        // the key is checked on a call that needs it, not only on /health
        $this->assertNotEmpty($this->requestsTo('GET', '/version'));
    }

    public function testSkipIndexLeavesGlobalSearchOff(): void
    {
        $host = $this->startFakeMeilisearch();
        System::meili_global_search(0);

        $this->runArtisan('exment:meili-setup', ['--host' => $host, '--key' => 'setup-test-key-0123456789', '--skip-env' => true, '--skip-index' => true, '--repair-at' => '04:30'])
            ->expectsOutputToContain('Index: skipped')
            ->assertExitCode(0)
            ->run();

        System::clearCache();
        $this->assertFalse(System::meili_global_search());
        $this->assertSame('04:30', System::meili_repair_at());
        $this->assertEmpty($this->requestsTo('POST', '/indexes'));
    }

    public function testWithoutAKeyItStopsWhenNoOneCanAnswer(): void
    {
        $host = $this->startFakeMeilisearch();
        System::meili_key(null);
        System::clearCache();
        config(['meilisearch.key' => null]);

        $this->runArtisan('exment:meili-setup', ['--host' => $host, '--skip-env' => true, '--no-interaction' => true])
            ->expectsOutputToContain('No master key')
            ->assertExitCode(1)
            ->run();

        $this->assertEmpty($this->requestsTo('GET', '/health'));
    }

    public function testRejectsABadIndexNameAndTime(): void
    {
        $this->runArtisan('exment:meili-setup', ['--host' => 'http://127.0.0.1:1', '--index' => 'a b', '--key' => 'k', '--skip-env' => true])
            ->expectsOutputToContain('The index name can use only')
            ->assertExitCode(1)
            ->run();

        $this->runArtisan('exment:meili-setup', ['--host' => 'http://127.0.0.1:1', '--repair-at' => '25:00', '--key' => 'k', '--skip-env' => true])
            ->expectsOutputToContain('--repair-at must be a time')
            ->assertExitCode(1)
            ->run();
    }

    public function testReadsTheKeyOfAMeilisearchConf(): void
    {
        $this->assertSame('abc123', MeiliSetupCommand::keyFromText("MEILI_DB_PATH=/var/lib/meilisearch/data.ms\nMEILI_MASTER_KEY=abc123\nMEILI_ENV=production\n"));
        $this->assertSame('abc123', MeiliSetupCommand::keyFromText("export MEILI_MASTER_KEY=\"abc123\"\r\n"));
        $this->assertSame('abc123', MeiliSetupCommand::keyFromText("abc123\n"));
        $this->assertSame('', MeiliSetupCommand::keyFromText("MEILI_ENV=production\n"));
        $this->assertSame('', MeiliSetupCommand::keyFromText(''));
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function runArtisan(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }

    private function startFakeMeilisearch(): string
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
            ['FAKE_MEILI_LOG' => $this->requestLog, 'FAKE_MEILI_HITS' => '[]', 'FAKE_MEILI_DOCS' => '0']
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

        return "http://127.0.0.1:{$port}";
    }

    /**
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
}
