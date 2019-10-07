<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Admin;
use Encore\Admin\Form;
use Encore\Admin\Form\Field;
use Encore\Admin\Form\Field\Hidden;
use Encore\Admin\Form\NestedForm;

/**
 * Class HasMany.
 */
class HasManyTable extends HasMany
{
    protected $tablecolumnwidths = [];
    protected $count = null;
    protected $header = true;

    /**
     * Show row up down button
     *
     * @var stringcolumn name
     */
    protected $rowUpDown = null;

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

    public function rowUpDown($rowUpDown)
    {
        $this->rowUpDown = $rowUpDown;
        return $this;
    }
    
    public function disableHeader()
    {
        $this->header = false;
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
        $field->setElementClass(['w-100'])->attribute(['style' => 'max-width:999999px']);
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
     * @return void
     */
    protected function setupScriptForDefaultView($templateScript)
    {
        $removeClass = NestedForm::REMOVE_FLAG_CLASS;
        $defaultKey = NestedForm::DEFAULT_KEY_NAME;
        $title = exmtrans("common.error");
        $message = sprintf(exmtrans("common.message.exists_row"), $this->label);
        $count = $this->count?? (!isset($this->value) ? 0 : count($this->value));
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
$('#has-many-table-{$this->column}').off('click', '.add').on('click', '.add', function () {
    var tpl = $('template.{$this->column}-tpl');

    $indexName++;

    var template = tpl.html().replace(/{$defaultKey}/g, $indexName);
    $('.has-many-table-{$this->column}-table tbody').append(template);

    {$templateScript}
    {$this->countscript}
});

$('#has-many-table-{$this->column}').off('click', '.remove').on('click', '.remove', function () {
    var row = $(this).closest('.has-many-table-{$this->column}-row');
    row.find('input,textarea,select').removeAttr('required max min maxlength pattern');
    row.hide();
    row.find('.$removeClass').val(1);
    {$this->countscript}
});

$('#has-many-table-{$this->column}').off('click', '.row-move').on('click', '.row-move', function(ev){
    var row = $(ev.target).closest('tr');
    var isup = $(ev.target).closest('.row-move').hasClass('row-move-up');
    
    var targetRow = getPrevNextRow(row, isup);
    if(!hasValue(targetRow)){
        return;
    }

    if(isup){
        targetRow.insertAfter(row);
    }else{
        row.insertAfter(targetRow);
    }
});

$("button[type='submit']").click(function(){
    if ($('#has-many-table-{$this->column}-table').attr('required') != undefined) {
        var cnt = $('#has-many-table-{$this->column}-table tr.has-many-table-{$this->column}-row').filter(':visible').length;
        if (cnt == 0) { 
            swal("$title", "$message", "error");
            return false;
        };
    }
    return true;
})

function getPrevNextRow(row, isup){
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
}

EOT;

        Admin::script($script);

        return $templateScript . $script;
    }

//     /**
//      * Setup tab template script.
//      *
//      * @param string $templateScript
//      *
//      * @return void
//      */
//     protected function setupScriptForTabView($templateScript)
//     {
//         $removeClass = NestedForm::REMOVE_FLAG_CLASS;
//         $defaultKey = NestedForm::DEFAULT_KEY_NAME;
//         $count = !isset($this->value) ? 0 : count($this->value);
//         $indexName = "index_{$this->column}";

//         $script = <<<EOT

// $('#has-many-{$this->column} > .nav').off('click', 'i.close-tab').on('click', 'i.close-tab', function(){
//     var \$navTab = $(this).siblings('a');
//     var \$pane = $(\$navTab.attr('href'));
//     if( \$pane.hasClass('new') ){
//         \$pane.remove();
//     }else{
//         \$pane.removeClass('active').find('.$removeClass').val(1);
//     }
//     if(\$navTab.closest('li').hasClass('active')){
//         \$navTab.closest('li').remove();
//         $('#has-many-{$this->column} > .nav > li:nth-child(1) > a').tab('show');
//     }else{
//         \$navTab.closest('li').remove();
//     }
// });

// var $indexName = {$count};
// $('#has-many-{$this->column} > .header').off('click', '.add').on('click', '.add', function(){
//     $indexName++;
//     var navTabHtml = $('#has-many-{$this->column} > template.nav-tab-tpl').html().replace(/{$defaultKey}/g, index);
//     var paneHtml = $('#has-many-{$this->column} > template.pane-tpl').html().replace(/{$defaultKey}/g, $indexName);
//     $('#has-many-{$this->column} > .nav').append(navTabHtml);
//     $('#has-many-{$this->column} > .tab-content').append(paneHtml);
//     $('#has-many-{$this->column} > .nav > li:last-child a').tab('show');
//     {$templateScript}
// });

// if ($('.has-error').length) {
//     $('.has-error').parent('.tab-pane').each(function () {
//         var tabId = '#'+$(this).attr('id');
//         $('li a[href="'+tabId+'"] i').removeClass('hide');
//     });
    
//     var first = $('.has-error:first').parent().attr('id');
//     $('li a[href="#'+first+'"]').tab('show');
// }
// EOT;

//         Admin::script($script);
//     }

    /**
     * Prepare for a field value before update or insert.
     *
     * @param $value
     *
     * @return mixed
     */
    public function prepare($value)
    {
        // if enable rowUpDown, remove row
        if (isset($this->rowUpDown) && (!is_null($value) && is_array($value))) {
            $order = 1;
            foreach ($value as &$v) {
                if ($v[Form::REMOVE_FLAG_NAME] == 1) {
                    continue;
                }
                array_set($v, $this->rowUpDown, $order++);
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
     * @return \Illuminate\View\View
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
        $grandParent = get_parent_class(get_parent_class(get_parent_class($this)));
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
            'options'      => $this->options,
            'header' => $this->header
        ]);
    }
}
