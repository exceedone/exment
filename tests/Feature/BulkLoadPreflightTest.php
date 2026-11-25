<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Exceptions\BulkLoadRejectedException;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\BulkLoad\BulkLoadContext;
use Exceedone\Exment\Services\BulkLoad\BulkLoadPreflight;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Support\DriverSql;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\Storage;

/**
 * Hybrid storage P1 W4: the preflight asked before exment:bulkinsert and restore write anything,
 * through every production entry point:
 *  - exment:bulkinsert (per file, before LOAD DATA);
 *  - exment:restore, admin/backup/import (stored or uploaded zip), admin/backup/restore - all
 *    through Restore::execute(), after the backup is unzipped and before the first table is
 *    replaced (the first statement of restoreDatabase() is SET FOREIGN_KEY_CHECKS=0).
 *
 * The backups here hold one tsv of a table that does not exist and no definition file, so even
 * an allowed restore replaces nothing (importTsv() skips a missing table). Needs local_infile on
 * the database server for the bulkinsert allow path, and the mysql client directory for the
 * backup check (XAMPP on the isolated host).
 */
class BulkLoadPreflightTest extends FeatureTestBase
{
    use DatabaseTransactions;
    use DriverSql;

    /** @var array<int, string> backup zips to delete */
    private array $zips = [];
    /** @var array<int, string> bulkinsert folders to delete */
    private array $dirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        config(['exment.backup_info.mysql_dir' => env('W4_MYSQL_DIR', 'C:\\xampp\\mysql\\bin')]);
    }

    protected function tearDown(): void
    {
        foreach ($this->zips as $zip) {
            Storage::disk(Define::DISKNAME_BACKUP_SYNC)->delete($zip);
            Storage::disk(Define::DISKNAME_ADMIN_TMP)->delete(basename($zip));
        }
        foreach ($this->dirs as $dir) {
            \File::deleteDirectory($dir);
        }
        if (app()->isDownForMaintenance()) {
            \Artisan::call('up');
        }
        System::clearCache();
        parent::tearDown();
    }

    /** A preflight that records each context, and refuses when $reject says so. */
    private function preflight(?callable $reject = null): object
    {
        $preflight = new class ($reject) implements BulkLoadPreflight {
            /** @var array<int, BulkLoadContext> */
            public array $contexts = [];
            /** @var callable|null */
            private $reject;

            public function __construct(?callable $reject)
            {
                $this->reject = $reject;
            }

            public function check(BulkLoadContext $context): void
            {
                $this->contexts[] = $context;
                if ($this->reject && ($this->reject)($context)) {
                    throw new BulkLoadRejectedException('w4 refused ' . $context->operation());
                }
            }
        };
        app()->instance(BulkLoadPreflight::class, $preflight);
        return $preflight;
    }

    /** A backup zip in the backup list holding one tsv of a missing table; returns its file name. */
    private function backupZip(): string
    {
        $name = 'w4_' . uniqid() . '.zip';
        $path = tempnam(sys_get_temp_dir(), 'w4zip');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('w4_no_such_table.tsv', "id\n");
        $zip->close();
        Storage::disk(Define::DISKNAME_BACKUP_SYNC)->put('list/' . $name, file_get_contents($path));
        unlink($path);
        $this->zips[] = 'list/' . $name;
        return $name;
    }

    /**
     * Run $callback with the query log; returns [result, whether restoreDatabase() started].
     *
     * @return array{0: mixed, 1: bool}
     */
    private function watched(\Closure $callback): array
    {
        \DB::flushQueryLog();
        \DB::enableQueryLog();
        try {
            $result = $callback();
        } finally {
            $log = \DB::getQueryLog();
            \DB::disableQueryLog();
        }
        $replaced = collect($log)->contains(function ($q) {
            return stripos($q['query'], 'SET FOREIGN_KEY_CHECKS=0') !== false;
        });
        return [$result, $replaced];
    }

    /**
     * A screen request: its response, or the exception it let through (the screens do not catch
     * a failed backup check either - it goes to the exception handler).
     *
     * @return array{response: mixed, exception: \Throwable|null}
     */
    private function attempt(\Closure $request): array
    {
        try {
            return ['response' => $request(), 'exception' => null];
        } catch (\Throwable $e) {
            return ['response' => null, 'exception' => $e];
        }
    }

    /**
     * Refused: the rejection was thrown out of the screen (or answered as an error) - never the
     * success answer.
     *
     * @param array{response: mixed, exception: \Throwable|null} $outcome
     */
    private function assertRefusedScreen(array $outcome): void
    {
        $exception = $outcome['exception'] ?? $outcome['response']->exception ?? null;
        $this->assertInstanceOf(BulkLoadRejectedException::class, $exception, 'the rejection reached the handler');
        if (isset($outcome['response'])) {
            // Exment's handler answers with its error page (as for a failed backup check today)
            $content = (string)$outcome['response']->baseResponse->getContent();
            $decoded = json_decode($content, true);
            $this->assertNotSame(true, is_array($decoded) ? ($decoded['result'] ?? null) : null, 'not the success answer');
            $this->assertStringNotContainsString((string)exmtrans('backup.message.restore_file_success'), $content);
        }
    }

    private function assertRestoreContext(BulkLoadContext $context, string $file): void
    {
        $this->assertSame(BulkLoadContext::OPERATION_RESTORE, $context->operation());
        $this->assertSame(['scope' => BulkLoadContext::TARGET_DATABASE, 'connection' => \DB::getDefaultConnection()], $context->target());
        $this->assertSame($file, $context->source()['file']);
        $this->assertSame(['tables' => ['w4_no_such_table'], 'has_definition' => false], $context->backup(), 'backup metadata, apart from the target');
    }

    // ----------------------------------------------------------------- restore

    /**
     * SQL Server: Exment's backup/restore is not available there (SqlServerConnection::checkBackup refuses). The
     * refusal comes BEFORE the preflight, so on this engine every restore path - allowed or refused by the
     * preflight - is the same explicit rejection: the not-supported message, the preflight is not asked,
     * no table is replaced, the application is up and the user stays logged in.
     */
    private function assertSqlServerRestoreRejected(object $spy, bool $replaced): void
    {
        $this->assertFalse($replaced, 'no table replaced');
        $this->assertCount(0, $spy->contexts, 'the engine refuses before the preflight is asked');
        $this->assertFalse(app()->isDownForMaintenance(), 'up');
        $this->assertTrue(\Auth::guard('admin')->check(), 'still logged in');
    }

    private function sqlServerNotSupportedMessage(): string
    {
        return (string)exmtrans('backup.message.not_support_driver', 'SQL Server');
    }

    public function testRestoreCommandAllowedRunsAsBefore(): void
    {
        $file = $this->backupZip();
        if (static::sqlServer()) {
            $spy = $this->preflight();
            [, $replaced] = $this->watched(function () use ($file) {
                return $this->artisan('exment:restore', ['file' => $file, '--yes' => true])
                    ->expectsOutputToContain($this->sqlServerNotSupportedMessage())
                    ->assertExitCode(1)
                    ->run();
            });
            $this->assertSqlServerRestoreRejected($spy, $replaced);
            return;
        }
        [$code, $replaced] = $this->watched(function () use ($file) {
            return \Artisan::call('exment:restore', ['file' => $file, '--yes' => true]);
        });
        $this->assertSame(0, $code, \Artisan::output());
        $this->assertTrue($replaced, 'restoreDatabase ran');
        $this->assertFalse(app()->isDownForMaintenance());
    }

    public function testRestoreCommandAllowedAsksThePreflightOnce(): void
    {
        $spy = $this->preflight();
        $file = $this->backupZip();
        if (static::sqlServer()) {
            $this->artisan('exment:restore', ['file' => $file, '--yes' => true])
                ->expectsOutputToContain($this->sqlServerNotSupportedMessage())
                ->assertExitCode(1);
            $this->assertSqlServerRestoreRejected($spy, false);
            return;
        }
        $this->assertSame(0, \Artisan::call('exment:restore', ['file' => $file, '--yes' => true]));
        $this->assertCount(1, $spy->contexts);
        $this->assertRestoreContext($spy->contexts[0], $file);
    }

    public function testRestoreCommandRefusedReplacesNothing(): void
    {
        $spy = $this->preflight(function () {
            return true;
        });
        $file = $this->backupZip();
        if (static::sqlServer()) {
            [, $replaced] = $this->watched(function () use ($file) {
                return $this->artisan('exment:restore', ['file' => $file, '--yes' => true])
                    ->expectsOutputToContain($this->sqlServerNotSupportedMessage())
                    ->assertExitCode(1)
                    ->run();
            });
            $this->assertSqlServerRestoreRejected($spy, $replaced);
            return;
        }
        [, $replaced] = $this->watched(function () use ($file) {
            // refused like any backup check: the message, exit code 1
            return $this->artisan('exment:restore', ['file' => $file, '--yes' => true])
                ->expectsOutputToContain('w4 refused restore')
                ->assertExitCode(1)
                ->run();
        });
        $this->assertFalse($replaced, 'no table replaced');
        $this->assertFalse(app()->isDownForMaintenance(), 'up again');
        $this->assertCount(1, $spy->contexts);
        $this->assertRestoreContext($spy->contexts[0], $file);
    }

    public function testRestoreScreensRefusedReplaceNothingAndDoNotSucceed(): void
    {
        $spy = $this->preflight(function () {
            return true;
        });

        if (static::sqlServer()) {
            // the three screens: the engine's explicit rejection reaches the handler, nothing is replaced
            $upload = tempnam(sys_get_temp_dir(), 'w4up');
            $zip = new \ZipArchive();
            $zip->open($upload, \ZipArchive::OVERWRITE);
            $zip->addFromString('w4_no_such_table.tsv', "id\n");
            $zip->close();
            $uploadName = 'w4_up_' . uniqid() . '.zip';
            $this->zips[] = 'list/' . $uploadName;
            $file = $this->backupZip();
            $requests = [
                function () use ($file) {
                    return $this->post(admin_url('backup/import'), ['restore_zipfile' => pathinfo($file, PATHINFO_FILENAME), 'restore_keyword' => Define::RESTORE_CONFIRM_KEYWORD]);
                },
                function () use ($upload, $uploadName) {
                    return $this->post(admin_url('backup/import'), ['upload_zipfile' => new \Illuminate\Http\UploadedFile($upload, $uploadName, 'application/zip', null, true), 'restore_keyword' => Define::RESTORE_CONFIRM_KEYWORD]);
                },
                function () use ($file) {
                    return $this->post(admin_url('backup/restore'), ['file' => $file]);
                },
            ];
            foreach ($requests as $i => $request) {
                [$outcome, $replaced] = $this->watched(function () use ($request) {
                    return $this->attempt($request);
                });
                $exception = $outcome['exception'] ?? $outcome['response']->exception ?? null;
                $this->assertInstanceOf(\Exceedone\Exment\Exceptions\BackupRestoreNotSupportedException::class, $exception, "screen $i");
                $this->assertSqlServerRestoreRejected($spy, $replaced);
            }
            @unlink($upload);
            return;
        }

        // backup/import of a stored backup
        $file = $this->backupZip();
        [$outcome, $replaced] = $this->watched(function () use ($file) {
            return $this->attempt(function () use ($file) {
                return $this->post(admin_url('backup/import'), ['restore_zipfile' => pathinfo($file, PATHINFO_FILENAME), 'restore_keyword' => Define::RESTORE_CONFIRM_KEYWORD]);
            });
        });
        $this->assertRefusedScreen($outcome);
        $this->assertFalse($replaced);

        // backup/import of an uploaded zip
        $upload = tempnam(sys_get_temp_dir(), 'w4up');
        $zip = new \ZipArchive();
        $zip->open($upload, \ZipArchive::OVERWRITE);
        $zip->addFromString('w4_no_such_table.tsv', "id\n");
        $zip->close();
        $uploadName = 'w4_up_' . uniqid() . '.zip';
        $this->zips[] = 'list/' . $uploadName;
        [$outcome, $replaced] = $this->watched(function () use ($upload, $uploadName) {
            return $this->attempt(function () use ($upload, $uploadName) {
                return $this->post(admin_url('backup/import'), [
                    'upload_zipfile' => new \Illuminate\Http\UploadedFile($upload, $uploadName, 'application/zip', null, true),
                    'restore_keyword' => Define::RESTORE_CONFIRM_KEYWORD,
                ]);
            });
        });
        $this->assertRefusedScreen($outcome);
        $this->assertFalse($replaced);

        // backup/restore
        $file2 = $this->backupZip();
        [$outcome, $replaced] = $this->watched(function () use ($file2) {
            return $this->attempt(function () use ($file2) {
                return $this->post(admin_url('backup/restore'), ['file' => $file2]);
            });
        });
        $this->assertRefusedScreen($outcome);
        $this->assertFalse($replaced);

        $this->assertCount(3, $spy->contexts, 'every screen asked');
        $this->assertSame([pathinfo($file, PATHINFO_FILENAME), $uploadName, $file2], array_map(function ($c) {
            return $c->source()['file'];
        }, $spy->contexts));
        $this->assertTrue(\Auth::guard('admin')->check(), 'not logged out as after a restore');
        $this->assertFalse(app()->isDownForMaintenance());
        @unlink($upload);
    }

    public function testRestoreScreenAllowedSucceedsAsBefore(): void
    {
        $file = $this->backupZip();
        if (static::sqlServer()) {
            $spy = $this->preflight();
            [$outcome, $replaced] = $this->watched(function () use ($file) {
                return $this->attempt(function () use ($file) {
                    return $this->post(admin_url('backup/restore'), ['file' => $file]);
                });
            });
            $exception = $outcome['exception'] ?? $outcome['response']->exception ?? null;
            $this->assertInstanceOf(\Exceedone\Exment\Exceptions\BackupRestoreNotSupportedException::class, $exception);
            $this->assertSqlServerRestoreRejected($spy, $replaced);
            return;
        }
        [$response, $replaced] = $this->watched(function () use ($file) {
            return $this->post(admin_url('backup/restore'), ['file' => $file]);
        });
        $response->assertJson(['result' => true]);
        $this->assertTrue($replaced);
    }

    // -------------------------------------------------------------- bulkinsert

    /** A bulkinsert folder with one csv per [table_name => rows]; returns its name. */
    private function bulkDir(array $files): string
    {
        $name = 'w4_' . uniqid();
        $dir = storage_path(path_join('app', 'bulkinsert', $name));
        \File::makeDirectory($dir, 0755, true);
        $this->dirs[] = $dir;
        foreach ($files as $file => $rows) {
            $fp = fopen(path_join($dir, $file), 'w');
            fputcsv($fp, ['id', 'suuid', 'value.text', 'value.index_text']);
            foreach ($rows as $row) {
                fputcsv($fp, $row);
            }
            fclose($fp);
        }
        return $name;
    }

    private function bulkRow(string $table, int $id): ?array
    {
        $value = \DB::table(getDBTableName($table))->where('id', $id)->value('value');
        return isset($value) ? json_decode($value, true) : null;
    }

    public function testBulkInsertAllowedLoadsAsBefore(): void
    {
        $dir = $this->bulkDir(['no_permission.csv' => [[99001, '', 'w4 text', 'w4 index']]]);
        $code = \Artisan::call('exment:bulkinsert', ['dir' => $dir]);
        if (static::sqlServer()) {
            // LOAD DATA is a MySQL / MariaDB statement: explicit refusal, nothing loaded
            $this->assertSame(-1, $code);
            $this->assertStringContainsString('not supported on SQL Server', \Artisan::output());
            $this->assertNull($this->bulkRow('no_permission', 99001));
            return;
        }
        $this->assertSame(0, $code, \Artisan::output());
        $this->assertSame(['text' => 'w4 text', 'index_text' => 'w4 index'], $this->bulkRow('no_permission', 99001));
    }

    public function testBulkInsertAllowedAsksThePreflightPerFile(): void
    {
        $spy = $this->preflight();
        $dir = $this->bulkDir(['no_permission.csv' => [[99002, '', 'w4 b', 'w4 b']]]);
        $code = \Artisan::call('exment:bulkinsert', ['dir' => $dir]);
        if (static::sqlServer()) {
            // the preflight is asked first (below); the engine then refuses LOAD DATA and nothing is loaded
            $this->assertSame(-1, $code);
            $this->assertStringContainsString('not supported on SQL Server', \Artisan::output());
            $this->assertNull($this->bulkRow('no_permission', 99002));
        } else {
            $this->assertSame(0, $code);
        }
        $this->assertCount(1, $spy->contexts);
        $context = $spy->contexts[0];
        $this->assertSame(BulkLoadContext::OPERATION_BULK_INSERT, $context->operation());
        $this->assertSame(['table_name' => 'no_permission', 'db_table_name' => getDBTableName('no_permission')], $context->target());
        $this->assertSame([], $context->backup());
        $this->assertStringEndsWith('no_permission.csv', $context->source()['csv']);
        if (!static::sqlServer()) {
            $this->assertNotNull($this->bulkRow('no_permission', 99002));
        }
    }

    public function testBulkInsertRefusedLoadsNothingOfThatFile(): void
    {
        $spy = $this->preflight(function () {
            return true;
        });
        $dir = $this->bulkDir(['no_permission.csv' => [[99003, '', 'w4 c', 'w4 c']]]);
        $code = \Artisan::call('exment:bulkinsert', ['dir' => $dir]);
        $this->assertSame(-1, $code, 'refused like any error');
        $this->assertStringContainsString('w4 refused bulkinsert', \Artisan::output());
        $this->assertNull($this->bulkRow('no_permission', 99003));
        $this->assertCount(1, $spy->contexts);
    }

    public function testBulkInsertRefusingALaterFileKeepsTheEarlierOnes(): void
    {
        // the files are asked one by one: a refusal stops the loop, files before it are loaded
        $edit = TestDefine::TESTDATA_TABLE_NAME_EDIT;
        $spy = $this->preflight(function (BulkLoadContext $c) {
            return $c->target()['table_name'] === 'no_permission';
        });
        // files are taken in name order: custom_value_edit before no_permission
        $dir = $this->bulkDir([
            "$edit.1.csv" => [[99004, '', 'w4 d', 'w4 d']],
            'no_permission.2.csv' => [[99005, '', 'w4 e', 'w4 e']],
        ]);
        $this->assertSame(-1, \Artisan::call('exment:bulkinsert', ['dir' => $dir]));
        if (static::sqlServer()) {
            // the engine refuses the first file (LOAD DATA): the loop stops there, the second file is never asked
            $this->assertStringContainsString('not supported on SQL Server', \Artisan::output());
            $this->assertSame([$edit], array_map(function ($c) {
                return $c->target()['table_name'];
            }, $spy->contexts));
            $this->assertNull($this->bulkRow($edit, 99004));
            $this->assertNull($this->bulkRow('no_permission', 99005));
            return;
        }
        $this->assertSame([$edit, 'no_permission'], array_map(function ($c) {
            return $c->target()['table_name'];
        }, $spy->contexts));
        $this->assertNotNull($this->bulkRow($edit, 99004), 'the earlier file was loaded');
        $this->assertNull($this->bulkRow('no_permission', 99005), 'the refused file was not');
    }
}
