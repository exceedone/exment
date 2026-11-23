<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Console\UpdateCommand;
use Illuminate\Testing\PendingCommand;

/**
 * exment:update on a production site: the database is updated only with --force or a
 * "yes" at the question. Without a terminal (a pipe, cron, ssh without a terminal) it
 * stops before it changes anything and says to add --force, instead of ending with
 * "migrate" cancelled and the database not updated.
 */
class UpdateCommandForceTest extends UnitTestBase
{
    /** @var string */
    protected $env;

    protected function setUp(): void
    {
        parent::setUp();
        $this->env = $this->app['env'];
    }

    protected function tearDown(): void
    {
        $this->app['env'] = $this->env;
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function pending(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        // artisan() answers an int only when the output is not mocked
        $this->assertInstanceOf(PendingCommand::class, $pending);
        return $pending;
    }

    public function testTheCommandHasForce(): void
    {
        $command = $this->app->make(UpdateCommand::class);
        $this->assertTrue($command->getDefinition()->hasOption('force'));
    }

    public function testProductionWithoutATerminalStopsAndSaysForce(): void
    {
        $this->app['env'] = 'production';

        $this->pending('exment:update', ['--no-interaction' => true])
            ->expectsOutputToContain('--force')
            ->assertExitCode(1);
    }

    public function testProductionAnsweredNoStops(): void
    {
        $this->app['env'] = 'production';

        $this->pending('exment:update')
            ->expectsConfirmation('This site is in production (APP_ENV=production). Update its database now?', 'no')
            ->assertExitCode(1);
    }
}
