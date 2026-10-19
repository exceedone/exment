<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Tests\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\PendingCommand;

/**
 * The 29 exment:* / passport:clientlist commands that had no test at all.
 *
 * Each one that can be driven into a real, DB-only, no-external-service code path
 * with a safe argument (an id/table/dir that does not exist, or simply no options)
 * is actually run through artisan() and checked against its real exit code - read
 * from the command's own handle(), not guessed.
 *
 * The rest touch something this suite must not touch for real: the filesystem
 * outside the DB transaction (backup/restore/install/publish/setup-dir), an
 * external service (meili-*, line-linkcode), or mail/notifications
 * (notify/notifytest) - or take a generic {action} with no documented safe value
 * (patchdata), or run the whole scheduler (schedule), which could fan out into any
 * of the above. For those, this only asserts the command is registered.
 */
class ConsoleCommandsTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
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

    // -- Safe to run for real: DB-only (or pure read), no filesystem/mail/external --

    public function testCheckConnectionReportsConnected(): void
    {
        // CheckConnectionCommand::handle(): "Connected" -> return 1 (yes, 1 means success here).
        $this->runArtisan('exment:check-connection')->assertExitCode(1);
    }

    public function testChecklangPassesOnTheShippedLanguageFiles(): void
    {
        $this->runArtisan('exment:checklang')->assertExitCode(0);
    }

    public function testCheckrequireAlwaysReturnsZero(): void
    {
        $this->runArtisan('exment:checkrequire')->assertExitCode(0);
    }

    public function testPassportClientlistListsRegisteredClients(): void
    {
        $this->runArtisan('passport:clientlist')->assertExitCode(0);
    }

    public function testVersionPrintsTheCurrentVersion(): void
    {
        $this->runArtisan('exment:version')->assertExitCode(0);
    }

    public function testLogClearWithForceAndAHugeKeepDaysIsANoOpDelete(): void
    {
        // --keep-days so large that nothing in a fresh test DB is older than it:
        // exercises the real delete query without risking real log rows.
        $this->runArtisan('exment:log-clear', ['--keep-days' => '36500', '--force' => true])
            ->assertExitCode(0);
    }

    public function testWorkflowClearWithAnUnknownTableReportsNotFound(): void
    {
        $this->runArtisan('exment:workflow-clear', [
            'table_name' => 'claude_coverage_does_not_exist',
            'id' => 999999,
        ])->assertExitCode(0);
    }

    public function testBulkinsertRejectsADirectoryArgumentContainingASeparator(): void
    {
        // BulkInsertCommand::handle() checks this before touching the filesystem.
        $this->runArtisan('exment:bulkinsert', ['dir' => 'a/b'])->assertExitCode(-1);
    }

    public function testImportRejectsADirectoryArgumentContainingASeparator(): void
    {
        // Same guard, same reason, as bulkinsert above.
        $this->runArtisan('exment:import', ['dir' => 'a/b'])->assertExitCode(-1);
    }

    public function testExportWithAnUnknownTableFailsBeforeBuildingTheGrid(): void
    {
        // getParameters() throws for an unknown table_name before the command
        // ever reaches the Grid/exporter code - never runs the real export.
        $this->runArtisan('exment:export', ['table_name' => 'claude_coverage_does_not_exist'])
            ->assertExitCode(-1);
    }

    public function testChunkexportWithAnUnknownTableFailsBeforeBuildingTheGrid(): void
    {
        $this->runArtisan('exment:chunkexport', ['table_name' => 'claude_coverage_does_not_exist'])
            ->assertExitCode(-1);
    }

    public function testResetpasswordWithNoTargetOptionFails(): void
    {
        // getParameters() requires one of --id/--email/--user_code; with none of
        // them, it throws before touching any real user's password.
        $this->runArtisan('exment:resetpassword')->assertExitCode(-1);
    }

    public function testBatchWithNoPluginOptionReportsPluginNotFound(): void
    {
        $this->runArtisan('exment:batch')->assertExitCode(1);
    }

    // -- Registered but not safe to run for real here: external service, real --
    // -- filesystem outside the transaction, mail/notifications, or unknown --
    // -- generic input. Each is still a real assertion (the command exists), --
    // -- not assertTrue(true). --

    /**
     * @return array<int, array<int, string>>
     */
    public static function provideRegistrationOnlyCommands(): array
    {
        return [
            ['exment:backup'],
            ['exment:install'],
            ['exment:line-linkcode'],
            ['exment:meili-health'],
            ['exment:meili-index'],
            ['exment:meili-reconcile'],
            ['exment:meili-search'],
            ['exment:meili-settings'],
            ['exment:notify'],
            ['exment:notifytest'],
            ['exment:patchdata'],
            ['exment:publish'],
            ['exment:restore'],
            ['exment:schedule'],
            ['exment:setup-dir'],
            ['exment:total-update'],
        ];
    }

    /**
     * @dataProvider provideRegistrationOnlyCommands
     */
    public function testCommandIsRegistered(string $command): void
    {
        $this->assertArrayHasKey($command, Artisan::all(), "Command $command is not registered.");
    }
}
