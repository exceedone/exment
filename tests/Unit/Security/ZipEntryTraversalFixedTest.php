<?php

namespace Exceedone\Exment\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use Exceedone\Exment\Exceptions\InvalidZipEntryException;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Services\BackupRestore\Restore;
use Exceedone\Exment\Services\ZipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use ZipArchive;

/**
 * Zip entry name validation (CWE-22 hardening) for every extractTo() in the package.
 *
 * WHAT THIS IS, AND WHAT IT IS NOT
 * --------------------------------
 * PHP's own ZipArchive::extractTo() already forces every entry back inside the target
 * directory (php_zip_make_relative_path(); the directory-entry hole was CVE-2014-9767,
 * fixed in 2015). test_php_extract_to_already_contains_traversal_entries below pins that
 * behaviour down, so nobody has to take it on trust - and so we find out loudly if a
 * future PHP release ever regresses it.
 *
 * So ZipService::validateZipEntries() is a SECOND line of defence. It earns its place
 * because several call sites reuse the RAW entry name as a path after extraction, where
 * no normalization happens at all:
 *   - PluginInstaller: $statname -> $config_path / $pluginFileBasePath via path_join(),
 *     and path_join() does not resolve '..'.
 *
 * It also closes a hole the first version of this fix opened: the rejected entry name is
 * attacker controlled, and the views that print upload errors still render the flash with
 * {!! !!}. The name therefore goes to the log only, never into the returned message.
 *
 * BEFORE FIX: all 6 extractTo() sites extracted whatever the archive contained, and the
 *             CSV import paths swallowed a failed open() with "//TODO:error".
 * AFTER FIX:  every site checks first; a refused archive produces a translated, entry-free
 *             message; the CSV import path surfaces it in the modal instead of a 500.
 */
class ZipEntryTraversalFixedTest extends SecurityRegressionTestCase
{
    /** @var string[] temp dirs to remove in tearDown */
    private array $tempRoots = [];

    protected function tearDown(): void
    {
        foreach ($this->tempRoots as $root) {
            $this->rrmdir($root);
        }
        $this->tempRoots = [];
        parent::tearDown();
    }

    // ---------------------------------------------------------------- the validator

    /** @return array<string, array{0: string}> */
    public static function unsafeEntryProvider(): array
    {
        return [
            'parent'                 => ['../evil.php'],
            'parent nested'          => ['a/../../evil.php'],
            'parent with dot segment' => ['a/./../../evil.php'],
            'parent alone'           => ['..'],
            'parent as last segment' => ['a/..'],
            'parent backslash'       => ['..\\evil.php'],
            'parent mixed separator' => ['a/..\\..\\evil.php'],
            'absolute unix'          => ['/etc/passwd'],
            'absolute backslash'     => ['\\windows\\system32\\x.dll'],
            'unc path'               => ['\\\\host\\share\\x.php'],
            'drive letter backslash' => ['C:\\evil.php'],
            'drive letter slash'     => ['C:/evil.php'],
            'drive letter bare'      => ['C:evil.php'],
            // even inside an otherwise fine name, '..' as a whole segment escapes
            'traversal in the middle' => ['plugin/../../evil.php'],
        ];
    }

    #[DataProvider('unsafeEntryProvider')]
    public function test_unsafe_entry_names_are_rejected(string $entryName): void
    {
        $this->assertTrue(
            ZipService::isUnsafeZipEntryName($entryName),
            "Entry name should have been rejected: {$entryName}"
        );
    }

    /** @return array<string, array{0: string}> */
    public static function safeEntryProvider(): array
    {
        return [
            'plain file'          => ['file.txt'],
            'nested file'         => ['ok/file.txt'],
            'dot segment'         => ['dir/sub/./file.txt'],
            // two dots INSIDE a name are not a traversal - this is the false-positive guard
            'dots inside name'    => ['my..file.txt'],
            'dated report'        => ['report..2026.csv'],
            'leading dots name'   => ['...evil.php'],
            'four dots name'      => ['....//evil.php'],
            'semicolon trick'     => ['..;/evil.php'],
            'two letter drive'    => ['ZZ:/evil.php'],
            'digit drive'         => ['1:/evil.php'],
            'japanese name'       => ['テンプレート/設定.json'],
            'space in name'       => ['my folder/my file.csv'],
        ];
    }

    #[DataProvider('safeEntryProvider')]
    public function test_safe_entry_names_are_allowed(string $entryName): void
    {
        $this->assertFalse(
            ZipService::isUnsafeZipEntryName($entryName),
            "Entry name should have been allowed: {$entryName}"
        );
    }

    // ------------------------------------------------------- the validator on real zips

    public function test_a_real_zip_with_a_traversal_entry_is_refused(): void
    {
        $zipPath = $this->makeZip(['ok/data.csv' => 'a,b', '../../evil.php' => '<?php echo 1;']);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);
        $message = ZipService::validateZipEntries($zip);
        $zip->close();

        $this->assertNotNull($message, 'A zip holding ../../evil.php must be refused.');
        $this->assertNotSame(
            'error.invalid_zip_entry',
            $message,
            'The translation key is missing from resources/lang: the raw key came back.'
        );
    }

    public function test_a_normal_zip_is_accepted(): void
    {
        $zipPath = $this->makeZip([
            'config.json'            => '{}',
            'template/lang/ja.json'  => '{}',
            'data/report..2026.csv'  => 'a,b',
        ]);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);
        $message = ZipService::validateZipEntries($zip);
        $zip->close();

        $this->assertNull($message, 'A zip of ordinary file names must still import.');
    }

    public function test_an_entry_name_holding_a_nul_byte_is_refused(): void
    {
        // ZipArchive::addFromString() cuts the name at the first NUL, so this archive has
        // to be written byte by byte - the way an attacker's tool would write it.
        //
        // Note what actually happens on PHP 8.3 / libzip: getNameIndex() hands the NUL
        // back as a space ("ok.csv /../../evil.php"), so it is the '..' segment check
        // that refuses this one, not the NUL check. The NUL check stays because other
        // libzip builds do return the byte, and a name that means one thing to PHP and
        // another to the filesystem is exactly what we do not want to extract.
        $root = $this->tempRoot();
        $zipPath = $root . '/raw.zip';
        $this->writeStoredZip($zipPath, "ok.csv\0/../../evil.php", 'x');

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);
        $message = ZipService::validateZipEntries($zip);
        $zip->close();

        $this->assertNotNull($message, 'A zip entry whose name holds a NUL must be refused.');
    }

    // --------------------------------------------------------------- the XSS regression

    public function test_the_refusal_message_never_carries_the_entry_name(): void
    {
        // The flash/alert views that show this message render with {!! !!}, and
        // Translator::makeReplacements() ends in strtr() - it escapes nothing. So the
        // entry name must not reach the message at all.
        $payload = '../<img src=x onerror=alert(1)>.php';
        $zipPath = $this->makeZip([$payload => 'x']);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);
        $message = ZipService::validateZipEntries($zip);
        $zip->close();

        $this->assertNotNull($message);
        $this->assertStringNotContainsString('<img', $message);
        $this->assertStringNotContainsString('onerror', $message);
        $this->assertStringNotContainsString($payload, $message);
    }

    public function test_the_flash_views_escape_their_message(): void
    {
        // Both plugin upload views print session('errorMess'), which already carried the
        // validator text built from the uploaded config.json before this fix.
        foreach (['plugin/upload.blade.php', 'plugin/editor/upload.blade.php'] as $view) {
            $path = dirname(__DIR__, 3) . '/resources/views/' . $view;
            $this->assertFileExists($path);
            $content = (string) file_get_contents($path);

            $this->assertStringNotContainsString(
                "{!! session('errorMess') !!}",
                $content,
                "{$view} still prints errorMess unescaped."
            );
            $this->assertStringContainsString(
                "{{ session('errorMess') }}",
                $content,
                "{$view} should print errorMess with the escaping braces."
            );
        }
    }

    // ------------------------------------------------------------------ the wiring

    /** @return array<string, array{0: string}> every extractTo() in the package */
    public static function extractSiteProvider(): array
    {
        return [
            'plugin upload'   => ['Services/Plugin/PluginInstaller.php'],
            'template import' => ['Services/TemplateImportExport/TemplateImporter.php'],
            'csv import'      => ['Services/DataImportExport/Formats/CsvTrait.php'],
            'csv import (phpspreadsheet)' => ['Services/DataImportExport/Formats/PhpSpreadSheet/Csv.php'],
            'backup restore'  => ['Services/BackupRestore/Restore.php'],
            'backup disk sync' => ['Storage/Disk/BackupDiskService.php'],
        ];
    }

    #[DataProvider('extractSiteProvider')]
    public function test_every_extract_site_validates_before_extracting(string $relativePath): void
    {
        $content = $this->exmentSource($relativePath);

        $extractPos  = strpos($content, '->extractTo(');
        $validatePos = strpos($content, 'ZipService::validateZipEntries(');

        $this->assertNotFalse($extractPos, "No extractTo() found in {$relativePath} - did it move?");
        $this->assertNotFalse($validatePos, "{$relativePath} extracts a zip without validating its entries.");
        $this->assertLessThan(
            $extractPos,
            $validatePos,
            "{$relativePath} validates after extracting, which is too late."
        );
    }

    public function test_the_csv_import_paths_no_longer_swallow_a_failed_open(): void
    {
        // numFiles is 0 on an archive that never opened, so the entry loop would run zero
        // times and report "safe". Both paths used to carry a bare "//TODO:error" here.
        foreach ([
            'Services/DataImportExport/Formats/CsvTrait.php',
            'Services/DataImportExport/Formats/PhpSpreadSheet/Csv.php',
        ] as $relativePath) {
            $content = $this->exmentSource($relativePath);

            $this->assertStringNotContainsString('//TODO:error', $content, "{$relativePath} still swallows a failed open().");
            $this->assertStringContainsString('InvalidZipEntryException', $content);
            // close() on an archive that never opened throws ValueError on PHP 8 and would
            // replace the real error, so the finally block has to know whether it opened.
            $this->assertStringContainsString('$zipOpened', $content, "{$relativePath} may close an unopened archive.");
        }
    }

    public function test_the_validator_signature_stays_nullable_string(): void
    {
        // A bool|string return made every caller pass string|false to a RuntimeException,
        // which phpstan level 7 (the level this repo runs) rejects. Keep it ?string.
        $content = $this->exmentSource('Services/ZipService.php');

        $this->assertStringContainsString(
            'public static function validateZipEntries(ZipArchive $zip): ?string',
            $content
        );
        $this->assertStringNotContainsString('@return bool|string', $content);
    }

    // ---------------------------------------------------- what PHP itself already does

    public function test_php_extract_to_already_contains_traversal_entries(): void
    {
        // Documents WHY the validator is hardening rather than the only thing standing
        // between an upload and the filesystem. If a PHP release ever stops normalizing,
        // this test fails and tells us the validator became load bearing.
        $root = $this->tempRoot();
        $target = $root . '/out/deep/inner';
        @mkdir($target, 0777, true);

        $zipPath = $this->makeZip([
            '../escaped_file.txt'                 => 'x',
            '../../../../../escaped_deeper.txt'   => 'x',
            '/absolute.txt'                       => 'x',
            'ok/plain.txt'                        => 'x',
        ], $root);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);
        $zip->extractTo($target);
        $zip->close();

        foreach (['escaped_file.txt', 'escaped_deeper.txt', 'absolute.txt'] as $name) {
            $this->assertFileExists($target . '/' . $name, "PHP should have pinned {$name} inside the target.");
            $this->assertFileDoesNotExist(dirname($root) . '/' . $name, "{$name} escaped above the test root.");
            $this->assertFileDoesNotExist($root . '/' . $name, "{$name} escaped the extract directory.");
        }
        $this->assertFileExists($target . '/ok/plain.txt');
    }

    // ------------------------------------------ refusing a zip must not become a bare 500

    public function test_the_exception_answers_an_ajax_request_by_itself(): void
    {
        // Most callers do NOT catch this exception: the installer, the public form import
        // and the backup restore all let it bubble up. Laravel calls render() on the
        // exception when it defines one, so that is where the safety net lives.
        $request = Request::create('/admin/template/import', 'POST');
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $response = (new InvalidZipEntryException('refused'))->render($request);

        $this->assertNotNull($response);
        // 400 is what getAjaxResponse() returns for every Exment error, so the front end
        // treats this exactly like the errors the controllers already return by hand.
        $this->assertSame(400, $response->getStatusCode());

        $body = json_decode(strval($response->getContent()), true);
        $this->assertIsArray($body);
        $this->assertFalse($body['result']);
        $this->assertSame('refused', $body['toastr']);
    }

    public function test_the_exception_answers_a_normal_request_with_a_redirect(): void
    {
        $request = Request::create('/admin/plugin', 'POST');

        $response = (new InvalidZipEntryException('refused'))->render($request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('refused', session('toastr')->first('message'));
    }

    /**
     * Every place that can receive the exception, and must not pass it on as a crash.
     *
     * render() alone is not enough: Exment replaces the exception handler, and
     * Exment::error() answers a logged in admin's non-ajax request with its own error page
     * before parent::render() - and therefore render() - is ever reached.
     *
     * @return array<string, array{0: string}>
     */
    public static function catchSiteProvider(): array
    {
        return [
            // csv/xlsx import modal
            'DataImportExportServiceTrait' => ['Services/DataImportExport/DataImportExportServiceTrait.php'],
            // template upload screen
            'TemplateController'           => ['Controllers/TemplateController.php'],
            // the three screens that call postInitializeForm() inside their own transaction
            'InitializeForm'               => ['Services/Installer/InitializeForm.php'],
            'SystemController'             => ['Controllers/SystemController.php'],
            'LoginSettingController'       => ['Controllers/LoginSettingController.php'],
            // public form import screen
            'CustomFormPublicController'   => ['Controllers/CustomFormPublicController.php'],
            // exment:restore; the console kernel uses renderForConsole(), never render()
            'RestoreCommand'               => ['Console/RestoreCommand.php'],
        ];
    }

    #[DataProvider('catchSiteProvider')]
    public function test_every_caller_turns_a_refused_zip_into_a_message(string $relativePath): void
    {
        $content = $this->exmentSource($relativePath);

        $this->assertStringContainsString(
            'use Exceedone\Exment\Exceptions\InvalidZipEntryException;',
            $content,
            "{$relativePath} does not import the exception it has to catch."
        );
        $this->assertMatchesRegularExpression(
            '/catch \(InvalidZipEntryException \$\w+\)/',
            $content,
            "{$relativePath} lets a refused zip become a crash."
        );
    }

    /**
     * The screens that open a transaction around postInitializeForm().
     *
     * @return array<string, array{0: string}>
     */
    public static function transactionSiteProvider(): array
    {
        return [
            'InitializeForm'         => ['Services/Installer/InitializeForm.php'],
            'SystemController'       => ['Controllers/SystemController.php'],
            'LoginSettingController' => ['Controllers/LoginSettingController.php'],
        ];
    }

    /**
     * A refused zip must not leave an open transaction behind.
     *
     * postInitializeForm() writes the system settings BEFORE it uploads the template, and
     * every caller wraps it in DB::beginTransaction(). Answering with a RedirectResponse
     * without rolling back would leave that transaction open for the rest of the request:
     * with the database session driver the flashed error itself would be rolled back and
     * the user would get the form again with no message at all.
     */
    #[DataProvider('transactionSiteProvider')]
    public function test_a_refused_zip_rolls_the_transaction_back(string $relativePath): void
    {
        $content = $this->exmentSource($relativePath);

        $catchBody = $this->blockAfter($content, 'catch (InvalidZipEntryException', 'return back(');
        $this->assertStringContainsString(
            'DB::rollback();',
            $catchBody,
            "{$relativePath} answers a refused zip without closing its transaction."
        );
    }

    /**
     * The same rule for the early return that hands the caller a validation redirect.
     */
    #[DataProvider('transactionSiteProvider')]
    public function test_an_early_redirect_rolls_the_transaction_back(string $relativePath): void
    {
        $content = $this->exmentSource($relativePath);

        $earlyReturn = $this->blockAfter($content, 'instanceof \Illuminate\Http\RedirectResponse', 'return $result;');
        $this->assertStringContainsString(
            'DB::rollback();',
            $earlyReturn,
            "{$relativePath} returns a redirect while its transaction is still open."
        );
    }

    public function test_the_shared_trait_does_not_answer_a_refused_zip_by_itself(): void
    {
        // postInitializeForm() runs inside the caller's transaction, so it must let the
        // exception through instead of turning it into a redirect the caller cannot undo.
        $content = $this->exmentSource('Services/Installer/InitializeFormTrait.php');

        $this->assertStringNotContainsString(
            'catch (InvalidZipEntryException',
            $content,
            'InitializeFormTrait swallows the exception inside the caller transaction again.'
        );
    }

    // ---------------------------------------- a zip that cannot be opened at all

    /**
     * A broken upload must not end up reported as a successful restore.
     *
     * Before: unzipFile() skipped the whole block and returned true, execute() then found
     * an empty folder, restored nothing, returned 0 - and BackupController answered
     * "restore succeeded" and logged the user out, with the data untouched.
     */
    public function test_an_unreadable_backup_zip_is_not_a_successful_restore(): void
    {
        $name = 'broken_' . short_uuid() . '.zip';
        $path = getFullpath($name, Define::DISKNAME_ADMIN_TMP);

        $dir = dirname($path);
        if (!\File::exists($dir)) {
            \File::makeDirectory($dir, 0755, true);
        }
        \File::put($path, 'this is not a zip file at all');

        try {
            $restore = new Restore();
            $unzipFile = new \ReflectionMethod($restore, 'unzipFile');
            $unzipFile->setAccessible(true);

            $this->expectException(InvalidZipEntryException::class);
            // second argument true = the "uploaded file" path, the one the screen uses
            $unzipFile->invoke($restore, $name, true);
        } finally {
            \File::delete($path);
        }
    }

    public function test_the_restore_no_longer_treats_a_failed_open_as_success(): void
    {
        // pin the shape too: the throw has to come from the open() check itself, not from
        // something further down that a later refactor could move away.
        $content = $this->exmentSource('Services/BackupRestore/Restore.php');

        $this->assertMatchesRegularExpression(
            '/if \(\$zip->open\(\$zipPath\) !== true\) \{\s*(?:\/\/[^\n]*\n\s*)*throw new InvalidZipEntryException/',
            $content,
            'Restore::unzipFile() swallows a failed open() again.'
        );
    }

    /**
     * Same false success, second door: restoring a saved backup from the list goes
     * through BackupDiskService::sync(), which also skipped a failed open() silently.
     */
    public function test_an_unreadable_stored_backup_zip_is_not_a_successful_restore(): void
    {
        $name = 'broken_' . short_uuid() . '.zip';
        $disk = \Storage::disk(Define::DISKNAME_BACKUP_SYNC);
        $disk->put('list/' . $name, 'this is not a zip file at all');

        $restore = new Restore();
        try {
            $unzipFile = new \ReflectionMethod($restore, 'unzipFile');
            $unzipFile->setAccessible(true);

            $this->expectException(InvalidZipEntryException::class);
            // second argument null = restore from the saved list, the common path
            $unzipFile->invoke($restore, $name, null);
        } finally {
            $disk->delete('list/' . $name);
            if ($restore->diskService() !== null) {
                $restore->diskService()->deleteTmpDirectory();
            }
        }
    }

    public function test_the_stored_backup_sync_no_longer_treats_a_failed_open_as_success(): void
    {
        $content = $this->exmentSource('Storage/Disk/BackupDiskService.php');

        $this->assertMatchesRegularExpression(
            '/if \(\$zip->open\(\$localSyncDiskItem->fileFullPath\(\)\) !== true\) \{\s*(?:\/\/[^\n]*\n\s*)*throw new InvalidZipEntryException/',
            $content,
            'BackupDiskService::sync() swallows a failed open() again.'
        );
    }

    // ------------------------------------------------ the password zip shell command

    public function test_the_linux_password_zip_quotes_every_path(): void
    {
        // $tmpFolderPath and $zipFullPath both grow out of the install directory. Without
        // quoting, an install path carrying a space cut the command in half and the
        // attachment silently never got zipped.
        $content = $this->exmentSource('Services/ZipService.php');

        $body = $this->blockAfter($content, 'function execPasswordZipLinux', 'if ($returnVar');

        foreach (['$tmpFolderPath', '$password', '$zipFullPath'] as $argument) {
            $this->assertStringContainsString(
                'escapeshellarg(' . $argument . ')',
                $body,
                "execPasswordZipLinux() passes {$argument} to the shell unquoted."
            );
        }

        $this->assertStringNotContainsString(
            "'(cd ' . \$tmpFolderPath",
            $body,
            'execPasswordZipLinux() builds the command by concatenation again.'
        );
    }

    public function test_the_linux_password_zip_reports_a_failed_command(): void
    {
        // exec() without a return var is how this failure stayed invisible for so long.
        $content = $this->exmentSource('Services/ZipService.php');

        $this->assertStringContainsString('exec($cmd, $output, $returnVar);', $content);
        $this->assertMatchesRegularExpression(
            '/if \(\$returnVar !== 0\) \{\s*(?:\/\/[^\n]*\n\s*)*\\\\Log::warning/',
            $content,
            'A failing password zip leaves nothing in the log.'
        );
    }

    public function test_the_windows_password_zip_is_left_alone(): void
    {
        // it already quotes all three arguments by hand, and the password is alphanumeric
        // (make_password(16, ['mark' => false])). Re-quoting it with escapeshellarg would
        // risk the 7z command line for no gain, so this test records that choice.
        $content = $this->exmentSource('Services/ZipService.php');

        $body = $this->blockAfter($content, 'function execPasswordZipWin', 'function execPasswordZipLinux');

        $this->assertStringContainsString('$dir7zip', $body);
        $this->assertStringNotContainsString('escapeshellarg', $body);
    }

    public function test_the_exception_keeps_its_own_fallback_response(): void
    {
        // BackupController posts its restore modal by ajax and catches nothing, so the
        // fallback in the exception is the only thing standing between it and a 500.
        $content = $this->exmentSource('Exceptions/InvalidZipEntryException.php');

        $this->assertStringContainsString('public function render($request)', $content);
    }

    // -------------------------------------------- the archives Exment itself produces

    public function test_a_backup_zip_built_the_way_exment_builds_it_is_accepted(): void
    {
        // Backup::createZip() walks the tmp folder with RecursiveDirectoryIterator and
        // stores substr($realPath, strlen($dir) + 1). If the validator ever refused one of
        // those names, every restore would break. This rebuilds that exact shape.
        $root = $this->tempRoot();
        $src = $root . '/backup_src';
        foreach (['database', 'storage/app/admin', 'storage/app/backup/list'] as $dir) {
            @mkdir($src . '/' . $dir, 0777, true);
        }
        file_put_contents($src . '/database/exment.sql', '-- dump');
        file_put_contents($src . '/database/custom_values.tsv', "id\tvalue\n");
        file_put_contents($src . '/storage/app/admin/添付 ファイル.pdf', 'x');
        file_put_contents($src . '/storage/app/backup/list/20260914.zip', 'x');
        file_put_contents($src . '/.env', 'APP_KEY=x');

        $zipPath = $root . '/backup.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src));
        foreach ($files as $file) {
            if ($file->isDir()) {
                continue;
            }
            $relativePath = substr($file->getRealPath(), strlen($src) + 1);
            $zip->addFile($file->getRealPath(), str_replace('\\', '/', $relativePath));
        }
        $zip->close();

        $check = new ZipArchive();
        $this->assertTrue($check->open($zipPath) === true);
        $this->assertGreaterThan(0, $check->numFiles);
        $this->assertNull(ZipService::validateZipEntries($check), 'Exment cannot restore its own backup any more.');
        $check->close();
    }

    public function test_a_template_zip_built_the_way_exment_builds_it_is_accepted(): void
    {
        // TemplateExporter writes config.json, lang/<locale>/lang.json and a thumbnail.
        $zipPath = $this->makeZip([
            'config.json'          => '{}',
            'lang/ja/lang.json'    => '{}',
            'lang/en/lang.json'    => '{}',
            'thumbnail.png'        => 'x',
        ]);

        $this->assertNull($this->validate($zipPath));
    }

    public function test_a_zip_carrying_directory_entries_is_accepted(): void
    {
        // 7-Zip and Windows Explorer store the folders too, as names ending in "/".
        // explode('/') then yields a trailing empty segment, which must not look like "..".
        $root = $this->tempRoot();
        $zipPath = $root . '/dirs.zip';

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addEmptyDir('plugin');
        $zip->addEmptyDir('plugin/views');
        $zip->addFromString('plugin/config.json', '{}');
        $zip->close();

        $this->assertNull($this->validate($zipPath));
    }

    public function test_a_zip_repacked_by_macos_or_windows_is_accepted(): void
    {
        $zipPath = $this->makeZip([
            '__MACOSX/._config.json'        => 'x',
            '.DS_Store'                     => 'x',
            'config.json'                   => '{}',
            'データ/顧客 一覧 (2026).csv'      => 'x',
            'sub dir/file - copy.csv'       => 'x',
        ]);

        $this->assertNull($this->validate($zipPath));
    }

    public function test_a_harmless_looking_dot_dot_segment_is_still_refused(): void
    {
        // Documented trade-off: "foo/../bar.csv" resolves to "bar.csv" and is harmless,
        // but the validator refuses it rather than resolving paths itself. If a customer
        // ever hits this with a real archive, THIS is the test to revisit.
        $this->assertTrue(ZipService::isUnsafeZipEntryName('foo/../bar.csv'));
    }

    // ------------------------------------------------------------------------ helpers

    /**
     * Cut the source between a marker and the statement that ends the block.
     *
     * Used to read what a catch block does before it answers, without parsing php.
     */
    private function blockAfter(string $content, string $marker, string $until): string
    {
        $start = strpos($content, $marker);
        $this->assertNotFalse($start, "marker not found: {$marker}");

        $end = strpos($content, $until, intval($start));
        $this->assertNotFalse($end, "end of block not found: {$until}");

        return substr($content, intval($start), intval($end) - intval($start));
    }

    /**
     * Open a zip on disk and run the validator over it.
     */
    private function validate(string $zipPath): ?string
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);
        $result = ZipService::validateZipEntries($zip);
        $zip->close();

        return $result;
    }

    /**
     * @param array<string, string> $entries entry name => content
     */
    private function makeZip(array $entries, ?string $root = null): string
    {
        $root = $root ?? $this->tempRoot();
        $zipPath = $root . '/test.zip';

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $zipPath;
    }

    /**
     * Write a one entry, stored (uncompressed) zip by hand, so the entry name can hold
     * bytes that ZipArchive::addFromString() refuses to store.
     */
    private function writeStoredZip(string $path, string $name, string $content): void
    {
        $crc = crc32($content);
        $len = strlen($content);
        $nameLen = strlen($name);

        $local = "PK\x03\x04"
            . pack('v', 10)   // version needed
            . pack('v', 0)    // flags
            . pack('v', 0)    // method: stored
            . pack('v', 0)    // mod time
            . pack('v', 0)    // mod date
            . pack('V', $crc)
            . pack('V', $len) // compressed size
            . pack('V', $len) // uncompressed size
            . pack('v', $nameLen)
            . pack('v', 0)    // extra length
            . $name
            . $content;

        $central = "PK\x01\x02"
            . pack('v', 0x0314) // version made by
            . pack('v', 10)
            . pack('v', 0)
            . pack('v', 0)
            . pack('v', 0)
            . pack('v', 0)
            . pack('V', $crc)
            . pack('V', $len)
            . pack('V', $len)
            . pack('v', $nameLen)
            . pack('v', 0)      // extra
            . pack('v', 0)      // comment
            . pack('v', 0)      // disk number
            . pack('v', 0)      // internal attributes
            . pack('V', 0)      // external attributes
            . pack('V', 0)      // offset of local header
            . $name;

        $eocd = "PK\x05\x06"
            . pack('v', 0)
            . pack('v', 0)
            . pack('v', 1)
            . pack('v', 1)
            . pack('V', strlen($central))
            . pack('V', strlen($local))
            . pack('v', 0);

        file_put_contents($path, $local . $central . $eocd);
    }

    private function tempRoot(): string
    {
        $root = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/exm_zipentry_' . uniqid();
        @mkdir($root, 0777, true);
        $this->tempRoots[] = $root;

        return $root;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
