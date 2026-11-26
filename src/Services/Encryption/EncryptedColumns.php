<?php

namespace Exceedone\Exment\Services\Encryption;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Services\Features;

/**
 * The columns kept encrypted in the database (ISS00148, stage 1).
 *
 * A column of a text type with the option "encrypted" keeps its values as EncryptedValue in
 * the json of the row: encrypted when the row is saved (encryptForSave, from the saving event
 * of CustomValue) and decrypted when the json is read (decryptValues, from its cast), so the
 * code that reads a row reads the plain value. The database, a dump and a backup hold only the
 * encrypted one; the key is not in them (DataKeys).
 *
 * The option is offered while the feature (Feature management, "encrypted_columns") is on. A
 * column already encrypted stays so when the feature is turned off: its values are still
 * decrypted to be read and encrypted when saved, until the option is turned off (which
 * converts the values back, as turning it on converts them: EncryptColumnJob).
 *
 * An encrypted value cannot be searched by a part, sorted or summed: the database sees only
 * the encrypted text. The option cannot be set with the search index, unique or multiple.
 */
class EncryptedColumns
{
    public const FEATURE = 'encrypted_columns';

    /** The column types that can be encrypted (text, not numbers, dates or references). */
    public const TYPES = [ColumnType::TEXT, ColumnType::TEXTAREA, ColumnType::EMAIL, ColumnType::URL];

    /** The options it cannot be set with. */
    public const CONFLICTS = ['index_enabled', 'unique', 'multiple_enabled'];

    public static function featureOn(): bool
    {
        return Features::enabled(static::FEATURE);
    }

    public static function supports(?string $columnType): bool
    {
        return in_array($columnType, static::TYPES, true);
    }

    /**
     * @param CustomColumn|null $column
     */
    public static function isEncrypted($column): bool
    {
        return isset($column) && static::supports($column->column_type) && boolval($column->getOption('encrypted'));
    }

    /**
     * How many last characters the masked value shows (0 to 4, default 4).
     */
    public static function visible(CustomColumn $column): int
    {
        $visible = $column->getOption('encrypted_mask_visible');
        return is_nullorempty($visible) ? 4 : max(0, min(4, intval($visible)));
    }

    /**
     * Whether the user of the request may see the plain values of the encrypted columns of a
     * table (of a record): "Show the encrypted values" of the role groups (a system
     * administrator may, as for every permission), and the record readable.
     *
     * @param mixed $id the record, null: the table
     */
    public static function canReveal(CustomTable $table, $id = null): bool
    {
        if (!$table->hasPermission([Permission::CUSTOM_VALUE_REVEAL])) {
            return false;
        }
        return !isset($id) || $table->hasPermissionData($id) === true;
    }

    /**
     * A value of an encrypted column as it is shown: "****1234". An encrypted text (a revision,
     * or a value whose key is not here) is decrypted first; one that cannot be is "cannot be read".
     *
     * @param mixed $value
     */
    public static function masked(CustomColumn $column, $value): string
    {
        if (is_nullorempty($value)) {
            return '';
        }
        $value = strval($value);
        if (EncryptedValue::isEncrypted($value)) {
            $plain = EncryptedValue::decrypt($value);
            if (!isset($plain)) {
                // the service of the keys not reached now (ISS02929), or the key is not here
                return strval(exmtrans(DataKeys::unavailable() !== null ? 'encrypted.unavailable' : 'encrypted.unreadable'));
            }
            $value = $plain;
        }
        return EncryptedValue::mask($value, static::visible($column));
    }

    /** @var bool|null set by a test: whether the save is the post of a form (null: from the request) */
    public static $formPost;

    /** @var bool the script of the buttons was added to the page */
    protected static $scriptAdded = false;

    /**
     * The script of the "show" buttons, once a page: the plain value asked for (and logged),
     * shown for a while, then masked again.
     */
    public static function revealScript(): void
    {
        if (static::$scriptAdded) {
            return;
        }
        static::$scriptAdded = true;
        $seconds = max(5, intval(config('exment.encrypted_reveal_seconds', 30))) * 1000;
        \ExmentAdminCore\Admin\Admin::script(<<<EOT
$(document).off('click.exmReveal').on('click.exmReveal', '.exm-encrypted-reveal', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var \$btn = $(this), \$text = \$btn.prevAll('.exm-encrypted').first(), masked = \$text.text();
    if (\$btn.prop('disabled')) { return; }
    \$btn.prop('disabled', true);
    $.ajax({ url: \$btn.data('url'), type: 'POST', data: { _token: LA.token } }).done(function (res) {
        \$text.text(res && res.value != null ? String(res.value) : '');
        setTimeout(function () { \$text.text(masked); \$btn.prop('disabled', false); }, {$seconds});
    }).fail(function (xhr) {
        \$btn.prop('disabled', false);
        toastr.error((xhr.responseJSON && xhr.responseJSON.message) || 'Error');
    });
});
EOT);
    }

    /**
     * Whether a value has the form of a masked value ("****", "****1234"): what an export or the
     * API gives. It is never written into an encrypted column.
     *
     * @param mixed $value
     */
    public static function isMaskLike($value): bool
    {
        return is_string($value) && preg_match('/^' . preg_quote(EncryptedValue::MASK, '/') . '.{0,4}$/u', $value) === 1;
    }

    /**
     * The masked value of what a row keeps now in a column ('' when it keeps none).
     *
     * @param mixed $stored the value of the json in the database (encrypted, or plain before the conversion)
     */
    public static function currentMask(CustomColumn $column, $stored): string
    {
        return is_nullorempty($stored) ? '' : static::masked($column, $stored);
    }

    /**
     * The errors of a save (the form, the API, an import: CustomTable::validateValue) that gives an
     * encrypted column a masked value other than the masked value it has now: it would write
     * "****" over the real value. The masked value it has now is taken as "not changed".
     *
     * @param mixed $input the values of the save (an array; anything else has no errors)
     * @param mixed $custom_value
     * @param array<string, mixed> $options
     * @return array<string, array<int, string>>
     */
    public static function maskErrors(CustomTable $table, $input, $custom_value, array $options = []): array
    {
        if (!is_array($input) || count(static::namesOf($table)) === 0) {
            return [];
        }
        $valuePrefix = boolval(array_get($options, 'addValue', true)) ? 'value.' : '';
        $errorPrefix = strval(array_get($options, 'column_name_prefix'));
        $original = [];
        if ($custom_value instanceof CustomValue && $custom_value->exists) {
            $original = json_decode_ex($custom_value->getRawOriginal('value'), true);
            $original = is_array($original) ? $original : [];
        }
        $errors = [];
        foreach ($table->custom_columns_cache as $column) {
            if (!static::isEncrypted($column) || !array_has($input, $valuePrefix . $column->column_name)) {
                continue;
            }
            $value = array_get($input, $valuePrefix . $column->column_name);
            if (!static::isMaskLike($value)) {
                continue;
            }
            if ($value === static::currentMask($column, $original[$column->column_name] ?? null)) {
                continue;
            }
            $errors[$errorPrefix . $column->column_name] = [strval(exmtrans('encrypted.masked_input'))];
        }
        return $errors;
    }

    /**
     * The json of a row as it leaves Exment (the API, MCP): the encrypted columns masked.
     *
     * @param mixed $values
     * @return mixed
     */
    public static function maskValues($values, ?CustomTable $table)
    {
        if (!is_array($values) || !isset($table)) {
            return $values;
        }
        foreach ($table->custom_columns_cache as $column) {
            if (static::isEncrypted($column) && array_key_exists($column->column_name, $values)) {
                $values[$column->column_name] = static::masked($column, $values[$column->column_name]);
            }
        }
        return $values;
    }

    /**
     * The names of the encrypted columns of a table.
     *
     * @param CustomTable|null $table
     * @return array<int, string>
     */
    public static function namesOf($table): array
    {
        if (!isset($table)) {
            return [];
        }
        $names = [];
        foreach ($table->custom_columns_cache as $column) {
            if (static::isEncrypted($column)) {
                $names[] = $column->column_name;
            }
        }
        return $names;
    }

    /**
     * The json of a row as it is read: the encrypted values decrypted. A value that cannot be
     * decrypted (its key is not here) stays as it is, so saving the row keeps it.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public static function decryptValues(array $values, ?CustomTable $table): array
    {
        foreach (static::namesOf($table) as $name) {
            $value = $values[$name] ?? null;
            if (EncryptedValue::isEncrypted($value)) {
                $plain = EncryptedValue::decrypt($value);
                if (isset($plain)) {
                    $values[$name] = $plain;
                }
            }
        }
        return $values;
    }

    /**
     * Encrypt the values of the encrypted columns of a row before it is written. A value that
     * did not change keeps the encrypted text it had (a new one each time would make every save
     * a change and a revision).
     */
    public static function encryptForSave(CustomValue $model): void
    {
        $names = static::namesOf($model->custom_table);
        if (count($names) === 0) {
            return;
        }
        $values = $model->value;
        if (!is_array($values)) {
            return;
        }
        $original = json_decode_ex($model->getRawOriginal('value'), true);
        $original = is_array($original) ? $original : [];

        // the form of a record shows an encrypted value empty: left empty, it stays as it was
        $keepEmpty = $model->exists && static::isFormPost();

        $changed = false;
        foreach ($names as $name) {
            $value = $values[$name] ?? null;
            $before = $original[$name] ?? null;
            if ($keepEmpty && is_nullorempty($value) && EncryptedValue::isEncrypted($before)) {
                $values[$name] = $before;
                $changed = true;
                continue;
            }
            // a masked value (an export imported again, the API): the value stays as it was.
            // One that is not the masked value it has was refused by maskErrors; never written
            if (static::isMaskLike($value)) {
                if (isset($before)) {
                    $values[$name] = $before;
                } else {
                    unset($values[$name]);
                }
                $changed = true;
                continue;
            }
            if (!is_string($value) || $value === '' || EncryptedValue::isEncrypted($value)) {
                continue;
            }
            if (EncryptedValue::isEncrypted($before) && !EncryptedValue::needsRotation($before) && EncryptedValue::decrypt($before) === $value) {
                $values[$name] = $before;
            } else {
                $values[$name] = EncryptedValue::encrypt($value);
            }
            $changed = true;
        }
        if ($changed) {
            $model->setAttribute('value', $values);
        }
    }

    /**
     * Whether the request is the post of the form of a record (not the API, not the inline edit
     * of a list, not a job).
     */
    public static function isFormPost(): bool
    {
        if (isset(static::$formPost)) {
            return static::$formPost;
        }
        $request = request();
        if (app()->runningInConsole() || is_null($request->route()) || !in_array($request->method(), ['POST', 'PUT'], true)) {
            return false;
        }
        return !\Exceedone\Exment\Services\UserApiKeys::isApiRoute($request) && $request->has('value');
    }

    /**
     * One value converted: encrypted (or encrypted again with the current key) or decrypted.
     * Null: nothing to do (or a value that cannot be decrypted, left as it is).
     *
     * @param mixed $value
     * @return string|null
     */
    public static function convertValue($value, bool $encrypt)
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        if ($encrypt) {
            if (!EncryptedValue::isEncrypted($value)) {
                return EncryptedValue::encrypt($value);
            }
            if (EncryptedValue::needsRotation($value)) {
                $plain = EncryptedValue::decrypt($value);
                return isset($plain) ? EncryptedValue::encrypt($plain) : null;
            }
            return null;
        }
        return EncryptedValue::isEncrypted($value) ? EncryptedValue::decrypt($value) : null;
    }
}
