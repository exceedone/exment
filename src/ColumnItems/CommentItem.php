<?php

namespace Exceedone\Exment\ColumnItems;

use Encore\Admin\Form\Field\Text;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;

class CommentItem extends SystemItem
{
    protected $table_name = 'comment_values';

    /**
     * constructor
     */
    public function __construct($custom_table, $custom_value)
    {
        $this->custom_table = $custom_table;
        $this->setCustomValue($custom_value);

        $this->column_name = SystemColumn::COMMENT;
        $this->label = exmtrans('common.format_keyvalue', 
            exmtrans("common.$this->column_name"), 
            exmtrans('system.filter_search_type_options.all'));
    }

    /**
     * whether column is enabled index.
     *
     */
    public function sortable()
    {
        return false;
    }

    /**
     * get sql query column name
     */
    protected function getSqlColumnName(bool $appendTable)
    {
        return 'comment';
    }

    public static function getItem(...$args)
    {
        list($custom_table, $custom_value) = $args + [null, null];
        return new self($custom_table, $custom_value);
    }

    /**
     * get text(for display)
     */
    protected function _text($v)
    {
        return null;
    }

    /**
     * get html(for display)
     * *this function calls from non-escaping value method. So please escape if not necessary unescape.
     */
    protected function _html($v)
    {
        return null;
    }

    public function getFilterField($value_type = null)
    {
        $field = new Text($this->name(), [$this->label()]);
        $field->default($this->value);
        return $field;
    }

    /**
     * get
     */
    public function getTableName()
    {
        return $this->table_name;
    }


    /**
     * get real table name.
     * If workflow, this name is workflow view.
     */
    public function sqlRealTableName()
    {
        return $this->getTableName();
    }

    /**
     * Get grid filter option. Use grid filter, Ex. LIKE search.
     *
     * @return string|null
     */
    protected function getGridFilterOption(): ?string
    {
        return (string)FilterOption::LIKE;
    }

    /**
     * Set admin filter options
     *
     * @param $filter
     * @return void
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    protected function setAdminFilterOptions(&$filter)
    {
        // Whether executed search.
        $searched = boolval(request()->get($filter->getId()));
        if ($searched) {
            System::setRequestSession(Define::SYSTEM_KEY_SESSION_COMMENT_FILTER_CHECK, true);
        }
    }
}
