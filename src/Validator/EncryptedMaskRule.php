<?php

namespace Exceedone\Exment\Validator;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Services\Encryption\EncryptedColumns;
use Illuminate\Contracts\Validation\Rule;

/**
 * The box of an encrypted column in the form of a record (ISS00148): a value with the form of a
 * masked value ("****1234") is refused, unless on an edit it is the masked value the record has
 * now (then it is "not changed"). On a new record there is none, so it is always refused: it
 * would never be saved (EncryptedColumns::encryptForSave), and a required column would be empty.
 */
class EncryptedMaskRule implements Rule
{
    /**
     * @param mixed $id the record, null: a new one
     */
    public function __construct(protected CustomColumn $column, protected $id = null)
    {
    }

    /**
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function passes($attribute, $value)
    {
        if (!EncryptedColumns::isMaskLike($value)) {
            return true;
        }
        if (!isset($this->id)) {
            return false;
        }
        $table = $this->column->custom_table_cache;
        if (!isset($table)) {
            return false;
        }
        $json = \DB::table(getDBTableName($table))->where('id', $this->id)->value('value');
        $stored = array_get(json_decode(strval($json), true) ?: [], $this->column->column_name);
        return $value === EncryptedColumns::currentMask($this->column, $stored);
    }

    /**
     * @return string
     */
    public function message()
    {
        return strval(exmtrans('encrypted.masked_form'));
    }
}
