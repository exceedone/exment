<?php

namespace Exceedone\Exment\Console;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Services\Encryption\ColumnConverter;
use Exceedone\Exment\Services\Encryption\DataKeys;
use Exceedone\Exment\Services\Encryption\EncryptedColumns;
use Illuminate\Console\Command;

/**
 * Convert the values already there of an encrypted column now, instead of the queue (ISS00148):
 * encrypt them when its option "encrypted" is on, decrypt them when it is off.
 *
 *   php artisan exment:encrypt-column table_name column_name
 */
class EncryptColumnCommand extends Command
{
    use CommandTrait;

    /**
     * @var string
     */
    protected $signature = 'exment:encrypt-column
                            {table : The table name}
                            {column : The column name}';

    /**
     * @var string
     */
    protected $description = 'Encrypt (or decrypt) the values of a column as its option "encrypted" says.';

    public function __construct()
    {
        parent::__construct();
        $this->initExmentCommand();
    }

    public function handle(): int
    {
        DataKeys::reset();
        $table = CustomTable::getEloquent(strval($this->argument('table')));
        /** @var CustomColumn|null $column */
        $column = isset($table) ? CustomColumn::getEloquent(strval($this->argument('column')), $table) : null;
        if (!isset($table, $column)) {
            $this->error('No such table or column.');
            return 1;
        }
        $encrypt = EncryptedColumns::isEncrypted($column);
        if ($encrypt && !DataKeys::hasKey()) {
            $this->error('No key: set DATA_ENCRYPTION_KEYS first (php artisan exment:data-key generate).');
            return 1;
        }
        $result = ColumnConverter::all($table, $column->column_name, $encrypt);
        $this->line(($encrypt ? 'Encrypted: ' : 'Decrypted: ') . $result['converted']);
        if ($result['unreadable'] > 0) {
            $this->warn("{$result['unreadable']} values have a key that is not in DATA_ENCRYPTION_KEYS: left as they are.");
        }
        return 0;
    }
}
