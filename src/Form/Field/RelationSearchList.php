<?php

namespace Exceedone\Exment\Form\Field;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Services\Search\RelationSearchSetting;
use ExmentAdminCore\Admin\Form\Field;

/**
 * The related tables the related search of a table shows, and their order
 * (#1570): one row per related table, a "show" checkbox, up and down buttons.
 */
class RelationSearchList extends Field
{
    protected $view = 'exment::form.field.relationsearchlist';

    /**
     * @var CustomTable|null
     */
    protected $custom_table;

    /**
     * The table whose related search this sets.
     *
     * @param CustomTable $custom_table
     * @return $this
     */
    public function customTable(CustomTable $custom_table)
    {
        $this->custom_table = $custom_table;
        return $this;
    }

    /**
     * Only the related tables of this table are kept, each once, in the order sent.
     *
     * @param mixed $value
     * @return mixed
     */
    public function prepare($value)
    {
        if (!isset($this->custom_table)) {
            return [];
        }
        return RelationSearchSetting::clean($this->custom_table, $value);
    }

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View|string
     */
    public function render()
    {
        $rows = [];
        if (isset($this->custom_table)) {
            foreach (RelationSearchSetting::rows($this->custom_table, false) as $row) {
                $rows[] = [
                    'key' => $row['key'],
                    'label' => RelationSearchSetting::labelOf($row['relation']),
                    'hidden' => $row['hidden'],
                ];
            }
        }

        // @phpstan-ignore-next-line
        return parent::render()->with([
            'rows' => $rows,
        ]);
    }
}
