<?php

namespace Exceedone\Exment\Model;

class Class_ImportConflictTest extends \Illuminate\Database\Eloquent\Model
{
    public function saved_notify($enabled)
    {
        return $this;
    }

    public function trashed()
    {
        return $this->deleted_at !== null;
    }
}

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Model\Class_ImportConflictTest;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Facades\ExmentFacade;
use Exceedone\Exment\Services\DataImportExport\Actions\Import\ActionInterface;
use Exceedone\Exment\Services\DataImportExport\Actions\Import\CustomTableAction;
use Exceedone\Exment\Services\DataImportExport\DataImportExportService;
use Exceedone\Exment\Services\DataImportExport\Formats\SpOut\Xlsx;
use Exceedone\Exment\Services\DataImportExport\Providers\Import\DefaultTableProvider;
use Exceedone\Exment\Services\DataImportExport\Providers\Import\ProviderBase;
use Illuminate\Container\Container;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\MessageBag;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ImportConflictTest extends TestCase
{
    private $originalContainer;
    private $originalFacadeApplication;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../../src/Services/Helpers.php';
        $this->originalContainer = Container::getInstance();
        $this->originalFacadeApplication = Facade::getFacadeApplication();
        $container = new Container();
        Container::setInstance($container);
        $loader = new FileLoader(new Filesystem(), __DIR__.'/../../resources/lang');
        $loader->addNamespace('exment', __DIR__.'/../../resources/lang');
        $container->instance('translator', new Translator($loader, 'en'));
        $factory = $this->createMock(ResponseFactory::class);
        $factory->method('make')->willReturnCallback(function ($content, $status, $headers) {
            return new Response($content, $status, $headers);
        });
        $container->instance(ResponseFactory::class, $factory);
    }

    protected function tearDown(): void
    {
        ExmentFacade::clearResolvedInstance(\Exceedone\Exment\Exment::class);
        Facade::setFacadeApplication($this->originalFacadeApplication);
        Container::setInstance($this->originalContainer);
        parent::tearDown();
    }

    public function testDeletedIdIsRejectedWithDataRowNumber(): void
    {
        $deleted = new Class_ImportConflictTest();
        $deleted->deleted_at = '2026-10-26 10:00:00';
        $provider = $this->provider([], [10 => $deleted], 0);
        $rows = $provider->getDataObject([
            ['id'], ['ID'], [9], [10],
        ], ['row_start' => 2]);
        [$valid, $errors] = $provider->validateImportData($rows);

        $this->assertSame([], $valid);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('application(Line 2)', $errors[0]);
        $this->assertStringContainsString('ID 10 belongs to a deleted record', $errors[0]);
        $this->assertFalse($rows[0]['model']->exists);
        $this->assertNotSame($deleted, $rows[0]['model']);
    }

    public function testExistingAndNewIdsKeepTheirModelBehavior(): void
    {
        $existing = new Class_ImportConflictTest();
        $existing->exists = true;
        $provider = $this->provider([1 => $existing], [], 2);
        $rows = $provider->getDataObject([['id'], ['ID'], [1], [2]]);
        [$valid, $errors] = $provider->validateImportData($rows);

        $this->assertSame([], $errors);
        $this->assertCount(2, $valid);
        $this->assertSame($existing, $rows[0]['model']);
        $this->assertFalse($rows[1]['model']->exists);
    }

    public function testDuplicateIdsInFileAreRejected(): void
    {
        $provider = $this->provider([], [], 1);
        $rows = $provider->getDataObject([['id'], ['ID'], [10], [10]]);
        [, $errors] = $provider->validateImportData($rows);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('application(Line 2)', $errors[0]);
        $this->assertStringContainsString('first occurrence: line 1', $errors[0]);
    }

    public function testUnmatchedExistingIdIsNotReportedAsDeleted(): void
    {
        $existing = new Class_ImportConflictTest();
        $provider = $this->provider([], [10 => $existing], 0);
        $rows = $provider->getDataObject([['id'], ['ID'], [10]]);
        [, $errors] = $provider->validateImportData($rows);

        $this->assertStringContainsString('could not be selected for update', $errors[0]);
        $this->assertStringNotContainsString('deleted record', $errors[0]);
    }

    public function testRepeatedIdsOfExistingRecordsKeepPreviousBehavior(): void
    {
        $existing = new Class_ImportConflictTest();
        $existing->exists = true;
        $provider = $this->provider([10 => $existing], [], 2);
        $rows = $provider->getDataObject([['id'], ['ID'], [10], [10]]);
        [$valid, $errors] = $provider->validateImportData($rows);

        $this->assertSame([], $errors);
        $this->assertCount(2, $valid);
    }

    public function testEmptyIdsRemainNewRecords(): void
    {
        $provider = $this->provider([], [], 2);
        $rows = $provider->getDataObject([['id'], ['ID'], [null], ['']]);
        [$valid, $errors] = $provider->validateImportData($rows);

        $this->assertCount(2, $valid);
        $this->assertSame([], $errors);
    }

    public function testOtherPrimaryKeysKeepExistingBehavior(): void
    {
        $existing = new Class_ImportConflictTest();
        $existing->exists = true;
        $provider = $this->provider(['abc' => $existing], [], 2, 'suuid');
        $rows = $provider->getDataObject([['suuid'], ['SUUID'], ['abc'], ['abc']]);
        [$valid, $errors] = $provider->validateImportData($rows);

        $this->assertCount(2, $valid);
        $this->assertSame([], $errors);
        $this->assertSame($existing, $rows[0]['model']);
    }

    public function testSuccessfulImportStillSavesEveryRow(): void
    {
        $provider = $this->createMock(ProviderBase::class);
        $rows = [['row_number' => 3], ['row_number' => 4]];
        $provider->method('getDataObject')->willReturn($rows);
        $provider->method('validateImportData')->willReturn([$rows, []]);
        $provider->expects($this->exactly(2))->method('importData');

        $result = $this->action($provider)->import(['application' => []]);
        $this->assertTrue($result['result']);
    }

    public function testValidationErrorsPreventSavingAnySheet(): void
    {
        $provider = $this->createMock(ProviderBase::class);
        $provider->method('getDataObject')->willReturn([]);
        $provider->method('validateImportData')->willReturnOnConsecutiveCalls(
            [[['row_number' => 3]], []],
            [[], ['application(Line 4): conflict']]
        );
        $provider->expects($this->never())->method('importData');
        $action = $this->action($provider);

        $response = $action->import(['application' => [], 'child' => []]);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse(json_decode($response->getContent(), true)['result']);
    }

    public function testUniqueConstraintIsReportedAndReturnedWithoutSql(): void
    {
        $exception = new UniqueConstraintViolationException(
            'mysql',
            'insert into secret_table values (?)',
            [],
            new \PDOException('Duplicate entry: private data', 23000)
        );
        $handler = $this->createMock(ExceptionHandler::class);
        $handler->expects($this->once())->method('report')->with($exception);
        Container::getInstance()->instance(ExceptionHandler::class, $handler);
        $provider = $this->createMock(ProviderBase::class);
        $rows = [['row_number' => 12], ['row_number' => 13]];
        $provider->method('getDataObject')->willReturn($rows);
        $provider->method('validateImportData')->willReturn([$rows, []]);
        $provider->expects($this->once())->method('importData')->willThrowException($exception);

        $response = $this->action($provider)->import(['application' => []]);
        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        $this->assertFalse($body['result']);
        $message = $body['errors']['import_error_message']['message'];
        $this->assertStringContainsString('application(Line 12)', $message);
        $this->assertStringContainsString('same ID or unique field value', $message);
        $this->assertStringContainsString('Earlier rows may already have been saved', $message);
        $this->assertStringNotContainsString('secret_table', $response->getContent());
        $this->assertStringNotContainsString('private data', $response->getContent());
    }

    public function testOtherDatabaseErrorsAreNotMisclassified(): void
    {
        $exception = new QueryException('mysql', 'select 1', [], new \PDOException('Connection failed'));
        $provider = $this->createMock(ProviderBase::class);
        $provider->method('getDataObject')->willReturn([]);
        $provider->method('validateImportData')->willReturn([[['row_number' => 3]], []]);
        $provider->method('importData')->willThrowException($exception);

        $this->expectException(QueryException::class);
        $this->action($provider)->import(['application' => []]);
    }

    public function testJapaneseConflictMessagesAreAvailable(): void
    {
        Container::getInstance()->make('translator')->setLocale('ja');
        foreach (['duplicate_id_in_file', 'deleted_id_conflict', 'id_conflict', 'unique_conflict', 'stopped_after_save_error', 'unexpected_error'] as $key) {
            $message = exmtrans('custom_value.import.'.$key, ['id' => 10, 'row' => 3]);
            $this->assertStringNotContainsString('exment::', $message);
            $this->assertStringNotContainsString(':id', $message);
            $this->assertStringNotContainsString(':row', $message);
        }
    }

    public function testUnexpectedErrorsAcrossTheImportPipelineAreReportedSafely(): void
    {
        foreach (['format', 'request_validation', 'reader', 'filter', 'action', 'plugin'] as $stage) {
            $exception = new \TypeError('private database details and server path');
            $handler = $this->createMock(ExceptionHandler::class);
            $handler->expects($this->once())->method('report')->with($exception);
            Container::getInstance()->instance(ExceptionHandler::class, $handler);

            $action = $this->createMock(ActionInterface::class);
            if ($stage === 'filter') {
                $action->method('filterDatalist')->willThrowException($exception);
            } else {
                $action->method('filterDatalist')->willReturnArgument(0);
            }
            if ($stage === 'action') {
                $action->method('import')->willThrowException($exception);
            }
            $format = $this->createMock(Xlsx::class);
            $format->method('filebasename')->willReturnSelf();
            if ($stage === 'reader') {
                $format->method('getDataTable')->willThrowException($exception);
            } else {
                $format->method('getDataTable')->willReturn(['application' => []]);
            }

            $this->initializeImportFacade();
            $service = $this->getMockBuilder(DataImportExportService::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['getFormatClass', 'validateRequest', 'customImport'])
                ->getMock();
            if ($stage === 'format') {
                $service->method('getFormatClass')->willThrowException($exception);
            } else {
                $service->method('getFormatClass')->willReturn($format);
            }
            if ($stage === 'request_validation') {
                $service->method('validateRequest')->willThrowException($exception);
            } else {
                $service->method('validateRequest')->willReturn(true);
            }
            if ($stage === 'plugin') {
                $service->method('customImport')->willThrowException($exception);
            }
            $service->importAction($action);

            $request = new Request($stage === 'plugin' ? ['import_plugin' => 1] : []);
            $response = $service->import($request);
            $this->assertSame(500, $response->getStatusCode(), $stage);
            $body = json_decode($response->getContent(), true);
            $this->assertFalse($body['result'], $stage);
            $this->assertNotEmpty($body['toastr'], $stage);
            $this->assertSame('input', $body['errors']['import_error_message']['type']);
            $this->assertStringContainsString('unexpected error', $body['errors']['import_error_message']['message']);
            $this->assertStringContainsString('Earlier rows may already have been saved', $body['errors']['import_error_message']['message']);
            $this->assertStringNotContainsString('private database', $response->getContent(), $stage);
        }
    }

    public function testExplicitHttpAndPermissionErrorsKeepTheirNormalHandling(): void
    {
        $validator = $this->createMock(Validator::class);
        $validator->method('errors')->willReturn(new MessageBag(['file' => ['Required']]));
        $exceptions = [
            new AccessDeniedHttpException('Forbidden'),
            new AuthorizationException('Forbidden'),
            new AuthenticationException(),
            new ValidationException($validator),
            new HttpResponseException(new Response('Explicit response', 422)),
        ];
        $handler = $this->createMock(ExceptionHandler::class);
        $handler->expects($this->never())->method('report');
        Container::getInstance()->instance(ExceptionHandler::class, $handler);
        foreach ($exceptions as $exception) {
            $service = $this->getMockBuilder(DataImportExportService::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['performImport'])
                ->getMock();
            $service->method('performImport')->willThrowException($exception);
            try {
                $service->import(new Request());
                $this->fail('Expected the original exception to be rethrown.');
            } catch (\Throwable $actual) {
                $this->assertSame($exception, $actual);
            }
        }
    }

    public function testNonRequestCallersKeepExceptionPropagation(): void
    {
        $exception = new \RuntimeException('patch import failed');
        $handler = $this->createMock(ExceptionHandler::class);
        $handler->expects($this->never())->method('report');
        Container::getInstance()->instance(ExceptionHandler::class, $handler);
        $service = $this->getMockBuilder(DataImportExportService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['performImport'])
            ->getMock();
        $service->method('performImport')->willThrowException($exception);

        $this->expectExceptionObject($exception);
        $service->import('C:\\templates\\mail_template.xlsx');
    }

    public function testServicePreservesSuccessfulAndStructuredErrorResponses(): void
    {
        $handler = $this->createMock(ExceptionHandler::class);
        $handler->expects($this->never())->method('report');
        Container::getInstance()->instance(ExceptionHandler::class, $handler);
        foreach ([
            ['result' => true, 'toastr' => 'Imported'],
            new Response(['result' => false, 'errors' => ['import_error_message' => ['type' => 'input', 'message' => 'Unique conflict']]], 400),
        ] as $result) {
            $service = $this->getMockBuilder(DataImportExportService::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['performImport'])
                ->getMock();
            $service->method('performImport')->willReturn($result);
            $this->assertSame($result, $service->import(new Request()));
        }
    }

    private function initializeImportFacade(): void
    {
        if (!class_exists('Exment')) {
            class_alias(ExmentFacade::class, 'Exment');
        }
        Facade::setFacadeApplication(Container::getInstance());
        $engine = $this->createMock(\Exceedone\Exment\Exment::class);
        ExmentFacade::swap($engine);
    }

    private function provider(array $active, array $conflicts, int $validRows, string $primaryKey = 'id'): DefaultTableProvider
    {
        $table = $this->getMockBuilder(CustomTable::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getMatchedCustomValues', 'setSelectTableValues', 'getAttribute'])
            ->getMock();
        $table->method('getAttribute')->willReturnCallback(function ($key) {
            return [
                'suuid' => 'ImportConflictTest',
                'table_name' => 'application',
                'custom_columns_cache' => [],
                'custom_columns' => [],
            ][$key] ?? null;
        });
        $table->method('getMatchedCustomValues')->willReturnCallback(function ($ids, $key, $withTrashed = false) use ($active, $conflicts) {
            return $withTrashed ? $conflicts : $active;
        });
        $provider = $this->getMockBuilder(DefaultTableProvider::class)
            ->setConstructorArgs([['custom_table' => $table, 'primary_key' => $primaryKey]])
            ->onlyMethods(['dataProcessingFirst', 'validateDataRow'])
            ->getMock();
        $provider->method('dataProcessingFirst')->willReturnArgument(0);
        $provider->expects($this->exactly($validRows))->method('validateDataRow')->willReturn(true);
        return $provider;
    }

    private function action(ProviderBase $provider): CustomTableAction
    {
        $action = $this->getMockBuilder(CustomTableAction::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getProvider'])
            ->getMock();
        $action->method('getProvider')->willReturn($provider);
        return $action;
    }
}
