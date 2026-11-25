<?php

namespace Exceedone\Exment\Model;

/**
 * One write of the JSON `value` about to run (ValueWriteObserver). Names and ids only - never a
 * value or a binding.
 */
final class ValueWriteObservation
{
    /** A model save whose value went through ValueWriter::prepare (column saving(), V-3, V-2). */
    public const KIND_PREPARED = 'prepared';
    /** A model save with disable_saving_event: no prepare, no saving trigger or plugin. */
    public const KIND_SAVING_DISABLED = 'saving_disabled';
    /** A model save with restore_revision: the revision value as it is, no prepare. */
    public const KIND_RESTORE_REVISION = 'restore_revision';
    /** ValueBulkWriter::removeKey: one JSON_REMOVE over the rows a query selects. */
    public const KIND_BULK_REMOVE_KEY = 'bulk_remove_key';
    /** ValueBulkWriter::setKey: one JSON_SET of one row. */
    public const KIND_BULK_SET_KEY = 'bulk_set_key';

    /** @var string */
    private $kind;
    /** @var string */
    private $db_table_name;
    /** @var mixed */
    private $id;
    /** @var string|null */
    private $column_name;

    /**
     * @param mixed $id
     */
    private function __construct(string $kind, string $db_table_name, $id, ?string $column_name)
    {
        $this->kind = $kind;
        $this->db_table_name = $db_table_name;
        $this->id = $id;
        $this->column_name = $column_name;
    }

    /**
     * A single-record save (prepared, saving_disabled, restore_revision) on the model's database
     * table (no metadata lookup); id is null on create.
     */
    public static function save(string $kind, CustomValue $model): self
    {
        return new self($kind, $model->getTable(), $model->exists ? $model->getKey() : null, null);
    }

    /**
     * A set-based statement on $db_table_name; $id when it targets one row.
     *
     * @param mixed $id
     */
    public static function bulk(string $kind, string $db_table_name, string $column_name, $id = null): self
    {
        return new self($kind, $db_table_name, $id, $column_name);
    }

    /**
     * Tell the bound observer (NullValueWriteObserver in production).
     */
    public static function emit(self $observation): void
    {
        app(ValueWriteObserver::class)->observe($observation);
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function dbTableName(): string
    {
        return $this->db_table_name;
    }

    /** @return mixed */
    public function id()
    {
        return $this->id;
    }

    /** The JSON key of a set-based statement. */
    public function columnName(): ?string
    {
        return $this->column_name;
    }
}
