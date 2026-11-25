<?php

namespace Exceedone\Exment\Services\Storage;

/**
 * render(): the public representation of a typed value, as read back from its staging column
 * (02 §15.2): integer / select_table / user / organization -> strval(int); decimal -> canonical
 * form D; date 'Y-m-d'; datetime 'Y-m-d H:i:s'; time 'H:i:s'; yesno '0'/'1'; strings verbatim.
 * Accepts both the PHP typed value (CellConverter) and the value the driver returns (MySQL /
 * MariaDB / pdo_sqlsrv string forms; SQL Server forms implemented from the driver documentation,
 * not yet verified on an instance).
 */
final class PublicValueRenderer
{
    /**
     * @param int|string|float|null $value
     */
    public static function render(string $type, $value): ?string
    {
        DriverSupport::require(DriverSupport::VERIFY);
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            // pdo_sqlsrv with SQLSRV_ATTR_FETCHES_DATETIME_TYPE returns DateTime objects
            $value = $value->format('Y-m-d H:i:s');
            if ($type === PhysicalColumnType::TIME) {
                $value = substr($value, 11);
            }
        }
        switch ($type) {
            case PhysicalColumnType::MEDIUMTEXT:
            case PhysicalColumnType::VARCHAR255:
            case PhysicalColumnType::VARCHAR768:
                return (string)$value;
            case PhysicalColumnType::BIGINT:
            case PhysicalColumnType::UINT:
            case PhysicalColumnType::TINYINT:
                return strval((int)$value);
            case PhysicalColumnType::DECIMAL:
                return CellConverter::decimalCanonical(static::decimalText((string)$value));
            case PhysicalColumnType::DATE:
                return substr((string)$value, 0, 10);
            case PhysicalColumnType::DATETIME:
                return substr((string)$value, 0, 19);
            case PhysicalColumnType::TIME:
                return substr((string)$value, 0, 8);
        }
        throw new \InvalidArgumentException("unknown physical type $type");
    }

    /**
     * A decimal as the driver returns it, as "[-]digits[.digits]": pdo_sqlsrv returns values
     * between -1 and 1 without the leading zero (".5", "-.5") unless SQLSRV_ATTR_FORMAT_DECIMALS
     * is set; MySQL / MariaDB return "0.5". (SQL Server: datetime "Y-m-d H:i:s.000" and time
     * "H:i:s.0000000" are cut to their first 19 / 8 characters by render().)
     */
    public static function decimalText(string $value): string
    {
        if (strncmp($value, '.', 1) === 0) {
            return '0' . $value;
        }
        if (strncmp($value, '-.', 2) === 0) {
            return '-0' . substr($value, 1);
        }
        return $value;
    }
}
