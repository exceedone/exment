<?php

namespace Exceedone\Exment\Model;

/**
 * File server, phase 4 (ISS00378): a column of a workspace (a "column" of a
 * SharePoint library). Each file of the workspace can have a value of it.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property string $label
 * @property string $field_type
 * @property string|null $options
 * @property bool $required
 * @property string|null $default_value
 * @property bool $show_in_list
 * @property int $order
 */
class FileMetadataField extends ModelBase
{
    public const TYPE_TEXT = 'text';
    public const TYPE_TEXTAREA = 'textarea';
    public const TYPE_NUMBER = 'number';
    public const TYPE_DATE = 'date';
    public const TYPE_SELECT = 'select';
    public const TYPE_MULTISELECT = 'multiselect';
    public const TYPE_BOOLEAN = 'boolean';

    public const TYPES = [
        self::TYPE_TEXT,
        self::TYPE_TEXTAREA,
        self::TYPE_NUMBER,
        self::TYPE_DATE,
        self::TYPE_SELECT,
        self::TYPE_MULTISELECT,
        self::TYPE_BOOLEAN,
    ];

    protected $casts = [
        'id' => 'integer',
        'workspace_id' => 'integer',
        'required' => 'boolean',
        'show_in_list' => 'boolean',
        'order' => 'integer',
    ];

    /**
     * The choices of a select or multiselect column.
     *
     * @return string[]
     */
    public function choices(): array
    {
        $lines = preg_split('/\r\n|\r|\n/', strval($this->options)) ?: [];
        $choices = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '' && !in_array($line, $choices, true)) {
                $choices[] = $line;
            }
        }
        return $choices;
    }

    public function hasChoices(): bool
    {
        return in_array($this->field_type, [static::TYPE_SELECT, static::TYPE_MULTISELECT], true);
    }
}
