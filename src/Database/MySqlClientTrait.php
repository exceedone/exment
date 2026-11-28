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
        return is_string($file) ? $file : path_join(sys_get_temp_dir(), 'exment-dump-' . bin2hex(random_bytes(8)));
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
        // a separator: the output of the client, then the hint
        return $message . ' / ' . strval(exmtrans('backup.message.tls_hint'));
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
     * fn(): array{0: bool, 1: bool}|null — whether LOAD DATA LOCAL INFILE is allowed on the server
     * and on the client, in place of asking the database (tests).
     *
     * @var callable|null
     */
    protected static $localInfileResolver = null;

    public static function localInfileUsing(?callable $resolver): void
    {
        static::$localInfileResolver = $resolver;
    }

    /**
     * Whether LOAD DATA LOCAL INFILE (the data of the custom tables, importTsv()) is allowed:
     * on the server (@@local_infile) and on the client (PDO::MYSQL_ATTR_LOCAL_INFILE).
     *
     * @return array{0: bool, 1: bool} [server, client]
     */
    protected function localInfileEnabled(): array
    {
        if (!is_null(static::$localInfileResolver)) {
            $resolved = call_user_func(static::$localInfileResolver);
            if (is_array($resolved)) {
                return [boolval($resolved[0] ?? false), boolval($resolved[1] ?? false)];
            }
        }

        $server = false;
        try {
            $row = (array) $this->selectOne('SELECT @@local_infile AS local_infile');
            $value = strtolower(strval(array_values($row)[0] ?? ''));
            $server = in_array($value, ['1', 'on', 'true'], true);
        } catch (\Throwable $e) {
            $server = false;
        }

        // the option of PDO (off by default; Exment turns it on, Initialize::initializeConfig()):
        // of this connection, or of the settings of the connection
        $client = false;
        if (defined('PDO::MYSQL_ATTR_LOCAL_INFILE')) {
            foreach ([$this->getConfig('options'), config('database.connections.' . $this->getName() . '.options')] as $options) {
                if (is_array($options) && boolval($options[\PDO::MYSQL_ATTR_LOCAL_INFILE] ?? false)) {
                    $client = true;
                }
            }
        }

        return [$server, $client];
    }

    /**
     * Whether an error of the database is about LOAD DATA LOCAL INFILE not being allowed.
     */
    public static function isLocalInfileError(string $message): bool
    {
        return preg_match('/\b3948\b|\b2068\b|local data is disabled|local_infile|LOAD DATA LOCAL INFILE file request rejected/i', $message) === 1;
    }

    /**
     * The message, and the hint about local_infile when the error is about it.
     */
    public static function withLocalInfileHint(string $message, string $error): string
    {
        if (!static::isLocalInfileError($error)) {
            return $message;
        }
        return $message . ' / ' . strval(exmtrans('backup.message.local_infile_hint'));
    }

    /**
     * Check a restore before the database is changed:
     * - the backup has something to restore, and a definition of the tables with its data
     * - every .sql file to import has data
     * - the mysql client connects (SELECT 1, with the options of the restore)
     * - LOAD DATA LOCAL INFILE is allowed, when there are data of the custom tables (.tsv)
     *
     * A backup of the files only (no data of the database) does not change the database: it is
     * not refused.
     *
     * @param string $dirFullPath the folder of the unzipped backup
     * @return void
     * @throws RestoreFailedException nothing was restored
     */
    public function checkRestore($dirFullPath)
    {
        $def = path_join($dirFullPath, config('exment.backup_info.def_file', 'table_definition.sql'));
        $contents = array_filter(\File::exists($dirFullPath) ? \File::allFiles($dirFullPath, true) : [], function ($file) {
            return $file->getFilename() !== \Exceedone\Exment\Model\Define::BACKUP_VERSION_FILE;
        });
        if (count($contents) === 0) {
            throw RestoreFailedException::nothingChanged(strval(exmtrans('backup.message.restore_backup_empty')));
        }

        $sqlFiles = static::restoreSqlFiles($dirFullPath);
        $tsvFiles = array_filter(\File::files($dirFullPath), function ($file) {
            return preg_match('/.+\.tsv$/i', $file->getFilename()) === 1;
        });
        if (count($sqlFiles) === 0 && count($tsvFiles) === 0) {
            // the files only: the database is not changed
            return;
        }
        if (!\File::exists($def)) {
            throw RestoreFailedException::nothingChanged(strval(exmtrans('backup.message.restore_no_definition', ['file' => basename($def)])));
        }

        foreach ($sqlFiles as $file) {
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

        // the data of the custom tables are loaded with LOAD DATA LOCAL INFILE
        if (count($tsvFiles) > 0) {
            [$server, $client] = $this->localInfileEnabled();
            if (!$server || !$client) {
                $reason = strval(exmtrans($server ? 'backup.message.restore_local_infile_client' : 'backup.message.restore_local_infile_off'));
                throw RestoreFailedException::nothingChanged($reason . ' / ' . strval(exmtrans('backup.message.local_infile_hint')));
            }
        }
    }

    /**
     * Load one .tsv file with LOAD DATA LOCAL INFILE.
     *
     * @return int the rows loaded
     * @throws RestoreFailedException the database is changed already (midway)
     */
    protected function loadTsvFile(string $query, string $file): int
    {
        try {
            return intval($this->getPdo()->exec($query));
        } catch (\Throwable $e) {
            $error = static::dumpErrorDetail($e->getMessage(), strval(config('database.connections.mysql.password', '')));
            throw RestoreFailedException::midway(static::withLocalInfileHint(strval(exmtrans('backup.message.restore_load_failed', [
                'file' => basename($file),
                'detail' => $error,
            ])), $error));
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
