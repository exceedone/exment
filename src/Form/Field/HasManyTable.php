<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Admin;
use Encore\Admin\Form;
use Encore\Admin\Form\Field;
use Encore\Admin\Form\Field\Hidden;
use Encore\Admin\Form\Field\Select;
use Encore\Admin\Form\NestedForm;

/**
 * Class HasMany.
 */
class HasManyTable extends HasMany
{
    protected $tablecolumnwidths = [];
    protected $count = null;
    protected $enableHeader = true;

    /**
     * Show row up down button
     *
     * @var string|null stringcolumn name
     */
    protected $rowUpDown = null;

    /**
     * power count for row up down
     *
     * @var int
     */
    protected $power = 1;

    /**
     * hide delete row no.
     * if set int, hide the line's button.
     *
     * @var integer
     */
    protected $hideDeleteButtonRow = null;

    /**
     * Options for template.
     *
     * @var array
     */
    protected $options = [
        'allowCreate' => true,
        'allowDelete' => true,
        'allowOptions' => true,
    ];

    /**
     * Width for table and set offset.
     *
     * @var array
     */
    protected $tablewidth = [
        'offset' => 1,
        'width' => 10,
    ];

    /**
     * display description
     */
    protected $description;

    /**
     * whether escape description
     */
    protected $escapeDescription = true;

    /**
     * set bootstrap table width
     */
    public function setTableWidth($width = 8, $offset = 2)
    {
        $this->tablewidth['width'] = $width;
        $this->tablewidth['offset'] = $offset;

        return $this;
    }

    /**
     * set bootstrap table Column width
     */
    public function setTableColumnWidth(...$width)
    {
        $this->tablecolumnwidths = $width;
        return $this;
    }

    public function description($description)
    {
        $this->description = $description;
        return $this;
    }

    public function descriptionHtml($description)
    {
        $this->escapeDescription = false;

        return $this->description($description);
    }

    /**
     * Set row updown button column name.
     *
     * @param string $rowUpDown
     * @param integer $power
     * @return $this
     */
    public function rowUpDown($rowUpDown, $power = 1)
    {
        $this->rowUpDown = $rowUpDown;
        $this->power = $power;
        return $this;
    }

    public function disableHeader()
    {
        $this->enableHeader = false;

        return $this;
    }

    /**
     * Disable Options.
     *
     * @return $this
     */
    public function disableOptions()
    {
        $this->options['allowOptions'] = false;

        return $this;
    }

    /**
     * Available views for HasMany field.
     *
     * @var array
     */
    protected $views = [
        'default' => 'exment::form.field.hasmanytable',
        'tab'     => 'admin::form.hasmanytable', // TODO:for tab
    ];

    /**
     * set table header and body from fields
     */
    protected function getTableItem(&$form)
    {
        $tableitems = [];
        $hiddens = [];
        $requires = [];
        $helps = [];

        foreach ($form->fields() as &$field) {
            // when embeds item,
            if ($field instanceof NestedEmbeds) {
                $embedfields = $field->fields();
                foreach ($embedfields as &$embedfield) {
                    $this->setTableFieldItem($embedfield, $tableitems, $hiddens, $requires, $helps);
                }
            } else {
                $this->setTableFieldItem($field, $tableitems, $hiddens, $requires, $helps);
            }
        }

        return [$tableitems, $hiddens, $requires, $helps];
    }

    /**
     * set table field item to header, body, hidden
     */
    protected function setTableFieldItem(&$field, &$tableitems, &$hiddens, &$requires, &$helps)
    {
        // if internal, skip field
        if ($field instanceof Internal) {
            return;
        }

        // if hidden, set $hiddens
        if ($field instanceof Hidden) {
            $hiddens[] = $field;
        } else {
            $tableitems[] = $field;
        }

        // if required true false
        $requires[] = is_array($field->getAttributes()) && array_has($field->getAttributes(), 'required');

        // set label viewclass hidden
        $field->setLabelClass(['hidden']);
        $field->setElementClass(['w-100']);
        if (!($field instanceof Select)) {
            $field->attribute(['style' => 'max-width:999999px']);
        }
        $field->setWidth(12, 0);

        // get help text
        if (!empty($help = $field->getHelpText())) {
            $helps[] = $help;
            $field->forgetHelp();
        } else {
            $helps[] = null;
        }
    }

    /**
     * Setup default template script.
     *
     * @param string $templateScript
     *
     * @return string
     */
    protected function setupScriptForDefaultView($templateScript)
    {
        $removeClass = NestedForm::REMOVE_FLAG_CLASS;
        $defaultKey = NestedForm::DEFAULT_KEY_NAME;
        $title = exmtrans("common.error");
        $message = sprintf(exmtrans("common.message.exists_row"), $this->label);
        $count = $this->getHasManyCount();
        $indexName = "index_{$this->column}";

        $rowUpDownClassName = $this->rowUpDown;

        /**
         * When add a new sub form, replace all element key in new sub form.
         *
         * @example comments[new___key__][title]  => comments[new_{index}][title]
         *
         * {count} is increment number of current sub form count.
         */
        $script = <<<EOT
var $indexName = {$count};
$('#has-many-table-{$this->column}').off('click.admin_add', '.add').on('click.admin_add', '.add', function () {
    var tpl = $('template.{$this->column}-tpl');

    $indexName++;

    var template = tpl.html().replace(/{$defaultKey}/g, $indexName);
    $('.has-many-table-{$this->column}-table tbody').append(template);

    {$templateScript}
    $(this).trigger('admin_hasmany_row_change');
});

$('#has-many-table-{$this->column}').off('click.admin_remove', '.remove').on('click.admin_remove', '.remove', function () {
    var row = $(this).closest('.has-many-table-{$this->column}-row');
    row.find('input,textarea,select').removeAttr('required max min maxlength pattern');
    row.hide();
    row.find('.$removeClass').val(1);
    $(this).trigger('admin_hasmany_row_change');
});

$('#has-many-table-{$this->column}').off('click.admin_row_remove', '.row-move').on('click.admin_row_remove', '.row-move', function(ev){
    var row = $(ev.target).closest('tr');
    var isup = $(ev.target).closest('.row-move').hasClass('row-move-up');

    let getPrevNextRow = function(row, isup){
        while(true){
            var targetRow = isup ? row.prev() : row.next();
            if(!hasValue(targetRow)){
                return;
            }
            if(targetRow.is(':visible')){
                return targetRow;
            }
            row = targetRow;
        }

        return null;
    };

    var targetRow = getPrevNextRow(row, isup);
    if(!hasValue(targetRow)){
        return;
    }

    if(isup){
        targetRow.insertAfter(row);
    }else{
        row.insertAfter(targetRow);
    }

    row.stop().css('background-color', '#FFFFCC').animate({backgroundColor: "rgba(0,0,0,0.0)"}, 1000);

});

$("button[type='submit']").click(function(){
    if ($('#has-many-table-{$this->column}-table').attr('required') === undefined) {
        return true;
    }
    var cnt = $('#has-many-table-{$this->column}-table tr.has-many-table-{$this->column}-row').filter(':visible').length;
    if (cnt == 0) {
        swal("$title", "$message", "error");
        return false;
    };
    return true;
});


EOT;

        Admin::script($script);

        return $templateScript . $script;
    }

    /**
     * Hide delete button's row no.
     *
     * @param int $rowNo
     * @return $this
     */
    public function hideDeleteButtonRow($rowNo)
    {
        $this->hideDeleteButtonRow = $rowNo;

        return $this;
    }

    /**
     * Prepare for a field value before update or insert.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    public function prepare($value)
    {
        // if enable rowUpDown, remove row
        if (isset($this->rowUpDown) && (!is_null($value) && is_array($value))) {
            $order = 1 * $this->power;
            foreach ($value as &$v) {
                if ($v[Form::REMOVE_FLAG_NAME] == 1) {
                    continue;
                }
                array_set($v, $this->rowUpDown, $order);
                $order = $order + $this->power;
            }
        }

        // call parent prepare
        return parent::prepare($value);
    }

    /**
     * Render the `HasMany` field.
     *
     * @throws \Exception
     *
     * @return \Illuminate\View\View|string
     */
    public function render()
    {
        // specify a view to render.
        $this->view = $this->views[$this->viewMode];

        // set header and body info
        $form = $this->buildNestedForm($this->column, $this->builder);

        list($template, $script) = $this->getTemplateHtmlAndScript($form);
        list($tableitems, $hiddens, $requires, $helps) = $this->getTableItem($form);

        // set related forms
        $relatedforms = [];
        // set labelclass hidden
        foreach ($this->buildRelatedForms() as $k => &$relatedform) {
            list($relatedtableitems, $relatedhiddens, $relatedrequires, $relatedhelps) = $this->getTableItem($relatedform);

            $relatedforms[$k] = [
                'tableitems' => $relatedtableitems,
                'hiddens' => $relatedhiddens,
                'requires' => $relatedrequires,
                'helps' => $relatedhelps,
            ];
        }
        $this->count = count($relatedforms);
        $this->setupScript($script);

        // get field class
        $grandParent = $this->getParentRenderClass();
        return $grandParent::render()->with([
            'forms'        => $relatedforms,
            'template'     => $template,
            'relationName' => $this->relationName,
            'hasRowUpDown' => isset($this->rowUpDown),
            'tableitems' => $tableitems,
            'hiddens' => $hiddens,
            'helps' => $helps,
            'requires' => $requires,
            'tablewidth' => $this->tablewidth,
            'tablecolumnwidths' => $this->tablecolumnwidths,
            'description' => $this->description,
            'escapeDescription' => $this->escapeDescription,
            'options'      => $this->options,
            'enableHeader' => $this->enableHeader,
            'hideDeleteButtonRow' => $this->hideDeleteButtonRow,
        ]);
    }


    protected function getParentRenderClass()
    {
        return get_parent_class(get_parent_class(get_parent_class($this)));
    }
}
