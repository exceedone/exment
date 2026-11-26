<?php

namespace Exceedone\Exment\Console;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Services\Encryption\ColumnConverter;
use Exceedone\Exment\Services\Encryption\DataKeys;
use Exceedone\Exment\Services\Encryption\EncryptedColumns;
use Exceedone\Exment\Services\Encryption\EncryptedValue;
use Exceedone\Exment\Services\Encryption\KeyProviders\KeyProviderException;
use Exceedone\Exment\Services\Encryption\KeyProviders\KeyProviders;
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
 *
 * With a service of the keys (ISS02929, EXMENT_DATA_KEY_PROVIDER), "generate" makes the key with
 * it: aws_kms asks KMS for a data key and prints only the wrapped one, vault wraps it with
 * Transit, aws_secrets_manager prints the new value of the secret (to put there by its owner).
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
        $before = DataKeys::all();
        if (!KeyProviders::isEnv() && DataKeys::unavailable() !== null) {
            $this->error('The keys cannot be read now: ' . DataKeys::unavailable());
            return 1;
        }
        try {
            $key = KeyProviders::make()->generate(DataKeys::nextId());
        } catch (KeyProviderException $e) {
            $this->error($e->getMessage());
            return 1;
        }
        $this->line(KeyProviders::isEnv()
            ? 'Put this first in DATA_ENCRYPTION_KEYS of .env (the keys there now after it):'
            : 'Set this (provider ' . KeyProviders::name() . '), the new key first:');
        $this->line('');
        $this->line($key['setting']);
        $this->line('');
        if (KeyProviders::isEnv()) {
            $this->warn('Keep a copy of the key in a safe place, apart from the server and its backups.');
            $this->warn('A value whose key is lost cannot be read again. A backup does not keep this key.');
        } else {
            $this->warn('Only a wrapped key or the value of the secret is shown: the key itself stays in the service.');
        }
        if (count($before) > 0) {
            $this->line('Then run "php artisan exment:data-key rotate" to encrypt the values again with it.');
        }
        return 0;
    }

    protected function status(): int
    {
        $keys = DataKeys::all();
        $this->line('Provider: ' . KeyProviders::name());
        if (DataKeys::unavailable() !== null) {
            $this->error('The keys cannot be read now: ' . DataKeys::unavailable());
        } elseif (count($keys) === 0) {
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
            $this->error(DataKeys::unavailable() !== null ? 'The keys cannot be read now: ' . DataKeys::unavailable() : 'No key: set DATA_ENCRYPTION_KEYS first.');
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
        // the secrets of the settings kept with the keys (mail OAuth, LINE WORKS; ISS02929)
        $secrets = \Exceedone\Exment\Services\Encryption\Secrets::resaveSettings();
        $this->line("settings: {$secrets['saved']}");
        if ($secrets['unreadable'] > 0) {
            $this->warn("{$secrets['unreadable']} secrets of the settings cannot be read: left as they are.");
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
