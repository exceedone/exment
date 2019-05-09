<?php

namespace Exceedone\Exment\ColumnItems;

use Exceedone\Exment\Form\Field;
use Exceedone\Exment\Enums\SummaryCondition;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\ViewColumnFilterType;

class SystemItem implements ItemInterface
{
    use ItemTrait;
    
    protected $column_name;
    
    protected $custom_table;
    
    public function __construct($custom_table, $column_name, $custom_value)
    {
        $this->custom_table = $custom_table;
        if (preg_match('/\d+-.+$/i', $column_name) === 1) {
            list($table_name, $this->column_name) = explode("-", $column_name);
        } else {
            $this->column_name = $column_name;
        }
        $this->custom_value = $custom_value;
        $this->label = exmtrans("common.$this->column_name");
    }

    /**
     * get column name
     */
    public function name()
    {
        return $this->column_name;
    }

    /**
     * get column key sql name.
     */
    public function sqlname()
    {
        if (boolval(array_get($this->options, 'summary'))) {
            return $this->getSummarySqlName();
        }
        return $this->getSqlColumnName();
    }

    /**
     * get column key refer to subquery.
     */
    public function getGroupName()
    {
        if (boolval(array_get($this->options, 'summary'))) {
            $summary_condition = SummaryCondition::getGroupCondition(array_get($this->options, 'summary_condition'));
            $alter_name = $this->sqlAsName();
            $raw = "$summary_condition($alter_name) AS $alter_name";
            return \DB::raw($raw);
        }
        return null;
    }

    /**
     * get sqlname for summary
     */
    protected function getSummarySqlName()
    {
        $column_name = $this->getSqlColumnName();

        $summary_option = array_get($this->options, 'summary_condition');
        $summary_condition = is_null($summary_option)? '': SummaryCondition::getEnum($summary_option)->lowerKey();
        $raw = "$summary_condition($column_name) AS ".$this->sqlAsName();

        return \DB::raw($raw);
    }

    /**
     * get sql query column name
     */
    protected function getSqlColumnName()
    {
        // get SystemColumn enum
        $option = SystemColumn::getOption(['name' => $this->column_name]);
        if (!isset($option)) {
            $sqlname = $this->column_name;
        } else {
            $sqlname = array_get($option, 'sqlname');
        }
        return getDBTableName($this->custom_table) .'.'. $sqlname;
    }

    protected function sqlAsName()
    {
        return "column_".array_get($this->options, 'summary_index');
    }

    /**
     * get index name
     */
    public function index()
    {
        $option = SystemColumn::getOption(['name' => $this->name()]);
        return array_get($option, 'sqlname', $this->name());
    }

    /**
     * get text(for display)
     */
    public function text()
    {
        return $this->getTargetValue();
    }

    /**
     * get html(for display)
     * *this function calls from non-escaping value method. So please escape if not necessary unescape.
     */
    public function html()
    {        
        return esc_html($this->text());
    }

    /**
     * sortable for grid
     */
    public function sortable()
    {
        return true;
    }

    public function setCustomValue($custom_value)
    {
        $this->custom_value = $custom_value;
        if (isset($custom_value)) {
            $this->id = $custom_value->id;
        }

        $this->prepare();
        
        return $this;
    }

    public function getCustomTable()
    {
        return $this->custom_table;
    }

    protected function getTargetValue()
    {
        // if options has "summary" (for summary view)
        if (boolval(array_get($this->options, 'summary'))) {
            return array_get($this->custom_value, $this->sqlAsName());
        }
        return array_get($this->custom_value, $this->column_name);
    }
    
    public function getAdminField($form_column = null, $column_name_prefix = null)
    {
        $field = new Field\Display($this->name(), [$this->label()]);
        $field->default($this->text());

        return $field;
    }

    /**
     * get view filter type
     */
    public function getViewFilterType()
    {
        switch ($this->column_name) {
            case 'id':
            case 'suuid':
            case 'parent_id':
                return ViewColumnFilterType::DEFAULT;
            case 'created_at':
            case 'updated_at':
                return ViewColumnFilterType::DAY;
            case 'created_user':
            case 'updated_user':
                return ViewColumnFilterType::USER;
        }
        return ViewColumnFilterType::DEFAULT;
    }

    public static function getItem(...$args)
    {
        list($custom_table, $column_name, $custom_value) = $args + [null, null, null];
        return new self($custom_table, $column_name, $custom_value);
    }
}
