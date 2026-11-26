<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Services\Encryption\EncryptedColumns;
use Exceedone\Exment\Services\Encryption\EncryptedValue;
use Exceedone\Exment\Services\LogManagement\DataAccessLogs;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * The plain value of an encrypted column, shown on purpose (ISS00148): the "show" button of the
 * list and the detail page. Only for a user with "Show the encrypted values" of the table who may
 * read the record; every reveal is written to the data access log (kind reveal, the column).
 */
class EncryptedValueController extends Controller
{
    /**
     * @param string $tableKey
     * @param mixed $id
     * @param string $column
     */
    public function reveal($tableKey, $id, $column): JsonResponse
    {
        $table = CustomTable::getEloquent($tableKey);
        /** @var CustomColumn|null $custom_column */
        $custom_column = isset($table) ? CustomColumn::getEloquent($column, $table) : null;
        if (!isset($table, $custom_column) || !EncryptedColumns::isEncrypted($custom_column) || !is_numeric($id)) {
            return response()->json(['message' => exmtrans('common.message.notfound')], 404);
        }
        if (!EncryptedColumns::canReveal($table, $id)) {
            return response()->json(['message' => trans('admin.deny')], 403);
        }
        /** @var CustomValue|null $record */
        $record = $table->getValueModel(intval($id));
        if (!isset($record)) {
            return response()->json(['message' => exmtrans('common.message.notfound')], 404);
        }

        $value = $record->getValue($custom_column->column_name);
        if (EncryptedValue::isEncrypted($value)) {
            // its key is not here
            return response()->json(['message' => exmtrans('encrypted.unreadable')], 422);
        }

        DataAccessLogs::revealed($table, intval($id), intval($custom_column->id));

        return response()->json(['value' => is_null($value) ? '' : strval($value)])
            ->header('Cache-Control', 'no-store');
    }
}
