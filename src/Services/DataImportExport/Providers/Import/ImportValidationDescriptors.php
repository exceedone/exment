<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Import;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Services\FormHelper;
use Exceedone\Exment\Validator;
use ExmentAdminCore\Admin\Form\Field;
use Illuminate\Support\Arr;

/**
 * Per-import-call cache of immutable validation descriptors (import path only).
 *
 * What is cached: the custom attribute (label) array of a table, and for every custom column the parsed rule list of its form field
 * when - and only when - that list cannot depend on the row: no init_only / hidden / view-only / internal column, a field that uses the
 * default Field::getValidator(), and rules that are plain strings or value objects listed in IMMUTABLE_RULES.
 * Everything else (CustomValueRule, InitOnlyRule, FileRequredRule, closures, file/list fields...) is NOT described, and
 * CustomTable::getValidateRules() builds it per row exactly as before.
 *
 * Rule objects are never handed out twice: freshRules() clones them for every row, so a validator never shares a rule instance
 * with another row. The instance lives only for one validateImportData() call; the key carries table / request method / user / locale.
 */
class ImportValidationDescriptors
{
    /**
     * Rule classes whose state is set only by the constructor and never changed by passes() / message().
     */
    protected const IMMUTABLE_RULES = [
        Validator\StringNumericRule::class,
        Validator\MaxLengthExRule::class,
        Validator\NumberMinRule::class,
        Validator\NumberMaxRule::class,
        Validator\IntegerCommaRule::class,
        Validator\DecimalCommaRule::class,
        Validator\SelectRule::class,
        Validator\SelectValTextRule::class,
        Validator\YesNoRule::class,
        Validator\BooleanRule::class,
        Validator\TimeRule::class,
    ];

    /**
     * @var array<string, mixed>
     */
    protected $store = [];

    /**
     * Build the cache key. Different tables, request methods, users, locales never share an entry.
     *
     * @param CustomTable $table
     * @param string $kind
     * @param array<int, mixed> $parts
     * @return string
     */
    public function key(CustomTable $table, string $kind, array $parts = []): string
    {
        $user = \Exment::user();
        return implode('|', array_merge([
            $kind,
            $table->id,
            request()->method(),
            is_null($user) ? '' : array_get($user, 'id'),
            app()->getLocale(),
        ], array_map(function ($part) {
            return is_bool($part) ? ($part ? '1' : '0') : (string)$part;
        }, $parts)));
    }

    /**
     * Get the cached value or build it once. A built value of null is cached too.
     *
     * @param string $key
     * @param callable $builder
     * @return mixed
     */
    public function remember(string $key, callable $builder)
    {
        if (!array_key_exists($key, $this->store)) {
            $this->store[$key] = $builder();
        }
        return $this->store[$key];
    }

    /**
     * Labels of CustomTable::getValidateCustomAttributes().
     *
     * @return array<string, string>
     */
    public function customAttributes(CustomTable $table, $systemColumn, $column_name_prefix, $appendKeyName): array
    {
        return $this->remember(
            $this->key($table, 'attributes', [$systemColumn, $column_name_prefix, $appendKeyName]),
            function () use ($table, $systemColumn, $column_name_prefix, $appendKeyName) {
                return $table->getValidateCustomAttributes($systemColumn, $column_name_prefix, $appendKeyName);
            }
        );
    }

    /**
     * Descriptors of all custom columns, in CustomTable::$custom_columns_cache order.
     *
     * @return array<int, array{column: CustomColumn, key: ?string, rules: ?array}>
     */
    public function columns(CustomTable $table, $column_name_prefix): array
    {
        return $this->remember(
            $this->key($table, 'columns', [$column_name_prefix]),
            function () use ($table, $column_name_prefix) {
                $descriptors = [];
                foreach ($table->custom_columns_cache as $custom_column) {
                    $descriptors[] = static::describeColumn($table, $custom_column, $column_name_prefix);
                }
                return $descriptors;
            }
        );
    }

    /**
     * Describe one column. 'rules' = null means "not cacheable: build per row".
     *
     * @return array{column: CustomColumn, key: ?string, rules: ?array}
     */
    public static function describeColumn(CustomTable $table, CustomColumn $custom_column, $column_name_prefix): array
    {
        $descriptor = ['column' => $custom_column, 'key' => null, 'rules' => null];

        $item = $custom_column->column_item;
        if (is_null($item)
            || boolval(array_get($custom_column->options, 'init_only'))
            || $item->hidden() || $item->initonly() || $item->viewonly() || $item->internal()) {
            return $descriptor;
        }

        $key = $column_name_prefix . $item->name();
        $field = FormHelper::getFormField($table, $custom_column, null, null, $column_name_prefix, true);
        if (!($field instanceof Field)
            || (new \ReflectionMethod($field, 'getValidator'))->getDeclaringClass()->getName() !== Field::class) {
            return $descriptor;
        }

        // the field returns no validator when the key is missing from the input, so probe with the key present
        $probe = [];
        Arr::set($probe, $key, null);
        $validator = $field->getValidator($probe);
        if (!$validator) {
            return $descriptor;
        }

        $parsed = $validator->getRules();
        if (array_keys($parsed) !== [$key]) {
            return $descriptor;
        }
        foreach ($parsed[$key] as $rule) {
            if (!static::isImmutableRule($rule)) {
                return $descriptor;
            }
        }

        $descriptor['key'] = $key;
        $descriptor['rules'] = $parsed;
        return $descriptor;
    }

    /**
     * @param mixed $rule
     * @return bool
     */
    public static function isImmutableRule($rule): bool
    {
        if (is_string($rule)) {
            return true;
        }
        return is_object($rule) && in_array(get_class($rule), static::IMMUTABLE_RULES, true);
    }

    /**
     * Copy a described rule array for one row: rule objects are cloned, strings are values.
     *
     * @param array<string, array<int, mixed>> $rules
     * @return array<string, array<int, mixed>>
     */
    public static function freshRules(array $rules): array
    {
        foreach ($rules as $attribute => $list) {
            foreach ($list as $i => $rule) {
                if (is_object($rule)) {
                    $rules[$attribute][$i] = clone $rule;
                }
            }
        }
        return $rules;
    }
}
