<?php

namespace Exceedone\Exment\Database;

use Exceedone\Exment\Exceptions\RestoreFailedException;

/**
 * The client commands of MySQL and MariaDB (mysqldump, mysql) for the backup and the restore:
 * their extra options from .env, their error output, and the checks before a restore.
 */
trait MySqlClientTrait
{
    /**
     * The extra options of a client command from .env, each one escaped:
     * EXMENT_DB_DUMP_OPTIONS (mysqldump, "dump_options") and EXMENT_DB_RESTORE_OPTIONS (mysql,
     * "restore_options"), e.g. "--ssl-mode=DISABLED" or "--defaults-extra-file=/path/my.cnf".
     *
     * @param string $key "dump_options" or "restore_options"
     */
    public static function clientOptions(string $key): string
    {
        $value = config('exment.backup_info.' . $key);
        if (!is_string($value) || trim($value) === '') {
            return '';
        }
        $options = preg_split('/\s+/', trim($value)) ?: [];
        return implode(' ', array_map('escapeshellarg', $options));
    }

    /**
     * Run a client command; its error output, without the password, is put in $errors.
     *
     * The error output goes to a file of the temporary folder of the system, redirected before
     * $redirect ("> file" or "< file"): the error of the shell opening that file is kept too.
     *
     * @param string $command the command (it holds the password: it is never written to a log)
     * @param string $redirect the redirect of the input or the output, "" for none
     * @param string|null $errors the error output (dumpErrorDetail())
     * @param string|null $password the password, masked in $errors
     * @return int the exit code
     */
    protected static function execClient(string $command, string $redirect, ?string &$errors = null, ?string $password = null): int
    {
        $errorFile = static::dumpErrorFile();
        exec(sprintf('%s 2> %s%s', $command, escapeshellarg($errorFile), $redirect === '' ? '' : ' ' . $redirect), $output, $exitCode);
        $raw = is_file($errorFile) ? strval(@file_get_contents($errorFile)) : '';
        @unlink($errorFile);
        $errors = static::dumpErrorDetail($raw, $password);

        return intval($exitCode);
    }

    /**
     * A new file for the error output of a client command, in the temporary folder of the system.
     */
    protected static function dumpErrorFile(): string
    {
        $file = @tempnam(sys_get_temp_dir(), 'exment-dump-');
        return is_string($file) && $file !== '' ? $file : path_join(sys_get_temp_dir(), 'exment-dump-' . bin2hex(random_bytes(8)));
    }

    /**
     * The error output of mysqldump / mysql to be shown: without the warning about the password on
     * the command line, without the password, and not too long.
     */
    public static function dumpErrorDetail(string $errors, ?string $password = null): string
    {
        $lines = collect(preg_split('/\r\n|\r|\n/', $errors) ?: [])
            ->map(function ($line) {
                return trim((string) $line);
            })
            ->filter(function ($line) {
                return $line !== '' && stripos($line, 'Using a password on the command line') === false;
            });

        $detail = $lines->implode(' ');
        // the argument of the password, as the command gives it
        $detail = (string) preg_replace('/--password=(\'[^\']*\'|"[^"]*"|\S*)/', '--password=***', $detail);
        // the password itself: a short one would also hide the words that hold its letters
        if (!is_null($password) && mb_strlen($password) >= 4) {
            $detail = str_replace($password, '***', $detail);
        }
        if (mb_strlen($detail) > 500) {
            $detail = mb_substr($detail, 0, 500) . '...';
        }
        return $detail;
    }

    /**
     * Whether the error output says that the client and the server do not agree on TLS
     * (ERROR 2026, a self-signed certificate, "SSL is required").
     */
    public static function isTlsError(string $errors): bool
    {
        return preg_match('/\b(?:ERROR|error:?)\s*2026\b|self[- ]signed certificate|SSL is required|TLS\/SSL error|certificate verify failed/i', $errors) === 1;
    }

    /**
     * The message, and the hint about TLS when the error output is about it.
     */
    public static function withTlsHint(string $message, string $errors): string
    {
        if (!static::isTlsError($errors)) {
            return $message;
        }
        return $message . ' ' . strval(exmtrans('backup.message.tls_hint'));
    }

    /**
     * The mysql command of the restore (with EXMENT_DB_RESTORE_OPTIONS), and its password.
     *
     * @return array{0: string, 1: string}
     */
    protected static function restoreClientCommand(): array
    {
        $host = config('database.connections.mysql.host', '');
        $username = config('database.connections.mysql.username', '');
        $password = strval(config('database.connections.mysql.password', ''));
        $database = config('database.connections.mysql.database', '');
        $dbport = config('database.connections.mysql.port', '');
        $charset = config('database.connections.mysql.charset', 'utf8mb4');

        $options = static::clientOptions('restore_options');
        $command = sprintf(
            '%s%s --default-character-set=%s -h %s -u %s --password=%s -P %s %s',
            static::getMysqlPath(),
            $options === '' ? '' : ' ' . $options,
            escapeshellarg($charset),
            escapeshellarg($host),
            escapeshellarg($username),
            escapeshellarg($password),
            escapeshellarg($dbport),
            escapeshellarg($database)
        );
        return [$command, $password];
    }

    /**
     * The .sql files a restore imports from the folder: the table definition first.
     *
     * @return array<int, string>
     */
    protected static function restoreSqlFiles(string $dirFullPath): array
    {
        $files = [];
        $def = path_join($dirFullPath, config('exment.backup_info.def_file', 'table_definition.sql'));
        if (\File::exists($def)) {
            $files[] = $def;
        }
        foreach (\File::files($dirFullPath) as $file) {
            $filename = $file->getFilename();
            // ignore "view_" file. (Bug fix v3.6.7)
            if (preg_match('/.+\.sql$/i', $filename) && !preg_match('/^view_.*/i', $filename) && $file->getRealPath() !== realpath($def)) {
                $files[] = strval($file->getRealPath());
            }
        }
        return $files;
    }

    /**
     * Check a restore before the database is changed: every .sql file to import has data, and
     * the mysql client connects (SELECT 1, with the options of the restore).
     *
     * @param string $dirFullPath the folder of the unzipped backup
     * @return void
     * @throws RestoreFailedException nothing was restored
     */
    public function checkRestore($dirFullPath)
    {
        foreach (static::restoreSqlFiles($dirFullPath) as $file) {
            clearstatcache(true, $file);
            if (!is_file($file) || filesize($file) === 0) {
                throw RestoreFailedException::nothingChanged(strval(exmtrans('backup.message.restore_empty_file', ['file' => basename($file)])));
            }
        }

        [$mysqlcmd, $password] = static::restoreClientCommand();
        $nul = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $exitCode = static::execClient(sprintf('%s -e %s', $mysqlcmd, escapeshellarg('SELECT 1')), '> ' . $nul, $errors, $password);
        if ($exitCode !== 0) {
            throw RestoreFailedException::nothingChanged(static::withTlsHint(strval(exmtrans('backup.message.restore_connect_failed', [
                'code' => $exitCode,
                'detail' => $errors,
            ])), strval($errors)));
        }
    }

    /**
     * Import one .sql file with the mysql client.
     *
     * @throws RestoreFailedException the database is changed already (midway)
     */
    protected static function importSqlFile(string $mysqlcmd, string $file, string $password): void
    {
        $exitCode = static::execClient($mysqlcmd, '< ' . escapeshellarg($file), $errors, $password);
        if ($exitCode !== 0) {
            throw RestoreFailedException::midway(static::withTlsHint(strval(exmtrans('backup.message.restore_import_failed', [
                'file' => basename($file),
                'code' => $exitCode,
                'detail' => $errors,
            ])), strval($errors)));
        }
    }
}
