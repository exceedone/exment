<?php

namespace Exceedone\Exment\Validator;

use Exceedone\Exment\Services\Encryption\DataKeys;
use Exceedone\Exment\Services\Encryption\EncryptedColumns;
use Illuminate\Contracts\Validation\Rule;

/**
 * The option "encrypted" of a column (ISS00148): a key must be there (DATA_ENCRYPTION_KEYS), and
 * the search index, unique and multiple cannot be on with it (the database sees only the
 * encrypted text).
 */
class EncryptedColumnRule implements Rule
{
    /** @var string */
    protected $message = '';

    /**
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function passes($attribute, $value)
    {
        if (!boolval($value)) {
            return true;
        }
        if (!EncryptedColumns::supports(strval(request()->input('column_type')))) {
            // the option is not shown for this type: it is not kept either
            return true;
        }
        if (!DataKeys::hasKey()) {
            $this->message = strval(exmtrans('custom_column.help.encrypted_no_key'));
            return false;
        }
        foreach (EncryptedColumns::CONFLICTS as $option) {
            if (boolval(request()->input("options.{$option}"))) {
                $this->message = strval(exmtrans('custom_column.help.encrypted_conflict', ['option' => exmtrans("custom_column.options.{$option}")]));
                return false;
            }
        }
        return true;
    }

    /**
     * @return string
     */
    public function message()
    {
        return $this->message;
    }
}
