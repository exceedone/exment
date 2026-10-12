<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Controllers\FileController;
use Exceedone\Exment\Enums\ErrorCode;
use Exceedone\Exment\Model\CustomTable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class FileParentPermissionTest extends TestCase
{
    private Container $previousContainer;

    private MockInterface $tables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
        // Use real response helpers without bootstrapping the app or its database.
        $app = new Application;
        $app->instance('translator', new Translator(new ArrayLoader, 'en'));
        $responses = Mockery::mock(ResponseFactory::class);
        $responses->shouldReceive('json')->andReturnUsing(
            static fn (array $data, int $status): JsonResponse => new JsonResponse($data, $status)
        );
        $app->instance(ResponseFactory::class, $responses);

        require_once dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'src'
            .DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'Helpers.php';

        $this->tables = Mockery::mock('alias:'.CustomTable::class);
    }

    protected function tearDown(): void
    {
        try {
            Mockery::close();
        } finally {
            Container::setInstance($this->previousContainer);
            parent::tearDown();
        }
    }

    public static function permissionCases(): array
    {
        $cases = [];
        foreach ([false, true] as $asApi) {
            foreach ([
                'parentless', 'missing_parent_id', 'missing_parent_type',
                'missing_table', 'missing_record', 'allowed', 'denied', 'non_boolean',
            ] as $state) {
                $cases[$state.($asApi ? '_api' : '_web')] = [$state, $asApi];
            }
        }

        return $cases;
    }

    #[DataProvider('permissionCases')]
    public function test_parent_permission_policy(string $state, bool $asApi): void
    {
        $data = (object) ['parent_id' => 7, 'parent_type' => 'parent_fixture'];
        $parentless = in_array($state, ['parentless', 'missing_parent_id', 'missing_parent_type'], true);
        if ($state === 'parentless' || $state === 'missing_parent_id') {
            $data->parent_id = null;
        }
        if ($state === 'parentless' || $state === 'missing_parent_type') {
            $data->parent_type = null;
        }

        if ($parentless) {
            $this->tables->shouldNotReceive('getEloquent');
        } else {
            $table = null;
            if ($state !== 'missing_table') {
                $record = null;
                if ($state !== 'missing_record') {
                    $record = Mockery::mock();
                    $record->shouldReceive('enableDelete')->once()
                        ->andReturn($state === 'non_boolean' ? 1 : $state === 'allowed');
                }
                $table = Mockery::mock();
                $table->shouldReceive('getValueModel')->once()->with(7)->andReturn($record);
            }
            $this->tables->shouldReceive('getEloquent')->once()
                ->with('parent_fixture')->andReturn($table);
        }

        $allowed = $parentless || $state === 'allowed';
        $method = new ReflectionMethod(FileController::class, 'checkParentPermission');

        if (! $allowed && ! $asApi) {
            try {
                $method->invoke(null, $data, ['asApi' => false]);
                $this->fail('An unresolved or denied declared parent must not be authorized.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }

            return;
        }

        $result = $method->invoke(null, $data, ['asApi' => $asApi]);
        if ($allowed) {
            $this->assertTrue($result);

            return;
        }

        $this->assertInstanceOf(JsonResponse::class, $result);
        $this->assertSame(403, $result->getStatusCode());
        $this->assertSame(ErrorCode::PERMISSION_DENY, $result->getData(true)['code']);
    }
}
