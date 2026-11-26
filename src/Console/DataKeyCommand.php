<?php

namespace Exceedone\Exment\Console;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Services\Encryption\ColumnConverter;
use Exceedone\Exment\Services\Encryption\DataKeys;
use Exceedone\Exment\Services\Encryption\EncryptedColumns;
use Exceedone\Exment\Services\Encryption\EncryptedValue;
use Illuminate\Console\Command;

/**
 * The keys of the encrypted columns (ISS00148, DataKeys):
 *
 *   php artisan exment:data-key generate   -- a new key, to put first in DATA_ENCRYPTION_KEYS
 *   php artisan exment:data-key status     -- the keys, and how many values each one keeps
 *   php artisan exment:data-key rotate     -- encrypt again with the current (first) key every
 *                                             value of an older one, after a new key was put first
 *
 * The command never writes .env and never prints a key it did not make.
 */
class DataKeyCommand extends Command
{
    use CommandTrait;

    /**
     * @var string
     */
    protected $signature = 'exment:data-key
                            {action : generate, status or rotate}';

    /**
     * @var string
     */
    protected $description = 'Manage the keys of the encrypted columns (generate, status, rotate).';

    public function __construct()
    {
        parent::__construct();
        $this->initExmentCommand();
    }

    public function handle(): int
    {
        DataKeys::reset();
        switch (strval($this->argument('action'))) {
            case 'generate':
                return $this->generate();
            case 'status':
                return $this->status();
            case 'rotate':
                return $this->rotate();
        }
        $this->error('The action is generate, status or rotate.');
        return 1;
    }

    protected function generate(): int
    {
        $key = DataKeys::generate();
        $current = strval(config('exment.data_encryption_keys', ''));
        $this->line('Put this first in DATA_ENCRYPTION_KEYS of .env (the keys there now after it):');
        $this->line('');
        $this->line('DATA_ENCRYPTION_KEYS="' . $key['pair'] . ($current === '' ? '' : ',' . $current) . '"');
        $this->line('');
        $this->warn('Keep a copy of the key in a safe place, apart from the server and its backups.');
        $this->warn('A value whose key is lost cannot be read again. A backup does not keep this key.');
        if ($current !== '') {
            $this->line('Then run "php artisan exment:data-key rotate" to encrypt the values again with it.');
        }
        return 0;
    }

    protected function status(): int
    {
        $keys = DataKeys::all();
        if (count($keys) === 0) {
            $this->warn('No key: DATA_ENCRYPTION_KEYS is empty. A column cannot be encrypted.');
        }
        $counts = [];
        foreach (static::encryptedColumns() as [$table, $column]) {
            $rows = \DB::table(getDBTableName($table))->pluck('value');
            foreach ($rows as $json) {
                $value = array_get(json_decode(strval($json), true) ?: [], $column->column_name);
                $id = EncryptedValue::keyId($value);
                if (isset($id)) {
                    $counts[$id] = ($counts[$id] ?? 0) + 1;
                }
            }
        }
        $lines = [];
        foreach ($keys as $id => $raw) {
            $lines[] = [$id, $id === DataKeys::currentId() ? 'current' : 'old', $counts[$id] ?? 0];
        }
        foreach ($counts as $id => $count) {
            if (!isset($keys[$id])) {
                $lines[] = [$id, 'MISSING', $count];
            }
        }
        $this->table(['key', 'state', 'values'], $lines);
        return 0;
    }

    protected function rotate(): int
    {
        if (!DataKeys::hasKey()) {
            $this->error('No key: set DATA_ENCRYPTION_KEYS first.');
            return 1;
        }
        $unreadable = 0;
        foreach (static::encryptedColumns() as [$table, $column]) {
            $result = ColumnConverter::all($table, $column->column_name, true);
            $unreadable += $result['unreadable'];
            $this->line("{$table->table_name}.{$column->column_name}: {$result['converted']}");
        }
        if ($unreadable > 0) {
            $this->warn("{$unreadable} values have a key that is not in DATA_ENCRYPTION_KEYS: left as they are.");
        }
        return 0;
    }

    /**
     * @return array<int, array{0: CustomTable, 1: CustomColumn}>
     */
    protected static function encryptedColumns(): array
    {
        $list = [];
        foreach (CustomColumn::query()->whereIn('column_type', EncryptedColumns::TYPES)->get() as $column) {
            /** @var CustomColumn $column */
            $table = CustomTable::getEloquent($column->custom_table_id);
            if (isset($table) && EncryptedColumns::isEncrypted($column)) {
                $list[] = [$table, $column];
            }
        }
        return $list;
    }
}
