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
        
        foreach ($form->fields() as &$field) {
            // when embeds item,
            if ($field instanceof NestedEmbeds) {
                $embedfields = $field->fields();
                foreach ($embedfields as &$embedfield) {
                    $this->setTableFieldItem($embedfield, $tableitems, $hiddens);
                }
            } else {
                $this->setTableFieldItem($field, $tableitems, $hiddens);
            }
        }

        return [$tableitems, $hiddens];
    }

    /**
     * set table field item to header, body, hidden
     */
    protected function setTableFieldItem(&$field, &$tableitems, &$hiddens)
    {
        // if hidden, set $hiddens
        if ($field instanceof Hidden) {
            $hiddens[] = $field;
        } else {
            $tableitems[] = $field;
        }

        // set label viewclass hidden
        $field->setLabelClass(['hidden']);
        $field->setWidth(12, 0);
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

        /**
         * When add a new sub form, replace all element key in new sub form.
         *
         * @example comments[new___key__][title]  => comments[new_{index}][title]
         *
         * {count} is increment number of current sub form count.
         */
        $script = <<<EOT
var index = 0;
$('#has-many-table-{$this->column}').on('click', '.add', function () {

    var tpl = $('template.{$this->column}-tpl');

    index++;

    var template = tpl.html().replace(/{$defaultKey}/g, index);
    $('.has-many-table-{$this->column}-table tbody').append(template);
    {$templateScript}
});

$('#has-many-table-{$this->column}').on('click', '.remove', function () {
    var row = $(this).closest('.has-many-table-{$this->column}-row');
    row.hide();
    row.find('.$removeClass').val(1);
    row.find('input textarea select').removeAttr('required max min maxlength pattern');
});

EOT;

        Admin::script($script);
    }

    /**
     * Setup tab template script.
     *
     * @param string $templateScript
     *
     * @return void
     */
    protected function setupScriptForTabView($templateScript)
    {
        $removeClass = NestedForm::REMOVE_FLAG_CLASS;
        $defaultKey = NestedForm::DEFAULT_KEY_NAME;

        $script = <<<EOT

$('#has-many-{$this->column} > .nav').off('click', 'i.close-tab').on('click', 'i.close-tab', function(){
    var \$navTab = $(this).siblings('a');
    var \$pane = $(\$navTab.attr('href'));
    if( \$pane.hasClass('new') ){
        \$pane.remove();
    }else{
        \$pane.removeClass('active').find('.$removeClass').val(1);
    }
    if(\$navTab.closest('li').hasClass('active')){
        \$navTab.closest('li').remove();
        $('#has-many-{$this->column} > .nav > li:nth-child(1) > a').tab('show');
    }else{
        \$navTab.closest('li').remove();
    }
});

var index = 0;
$('#has-many-{$this->column} > .header').off('click', '.add').on('click', '.add', function(){
    index++;
    var navTabHtml = $('#has-many-{$this->column} > template.nav-tab-tpl').html().replace(/{$defaultKey}/g, index);
    var paneHtml = $('#has-many-{$this->column} > template.pane-tpl').html().replace(/{$defaultKey}/g, index);
    $('#has-many-{$this->column} > .nav').append(navTabHtml);
    $('#has-many-{$this->column} > .tab-content').append(paneHtml);
    $('#has-many-{$this->column} > .nav > li:last-child a').tab('show');
    {$templateScript}
});

if ($('.has-error').length) {
    $('.has-error').parent('.tab-pane').each(function () {
        var tabId = '#'+$(this).attr('id');
        $('li a[href="'+tabId+'"] i').removeClass('hide');
    });
    
    var first = $('.has-error:first').parent().attr('id');
    $('li a[href="#'+first+'"]').tab('show');
}
EOT;

        Admin::script($script);
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
        list($tableitems, $hiddens) = $this->getTableItem($form);

        // set related forms
        $relatedforms = [];
        // set labelclass hidden
        foreach ($this->buildRelatedForms() as $k => &$relatedform) {
            list($relatedtableitems, $relatedhiddens) = $this->getTableItem($relatedform);

            $relatedforms[$k] = [
                'tableitems' => $relatedtableitems,
                'hiddens' => $relatedhiddens
            ];
        }

        $this->setupScript($script);

        // get field class
        $grandParent = get_parent_class(get_parent_class(get_parent_class($this)));
        return $grandParent::render()->with([
            'forms'        => $relatedforms,
            'template'     => $template,
            'relationName' => $this->relationName,
            'tableitems' => $tableitems,
            'hiddens' => $hiddens,
            'tablewidth' => $this->tablewidth,
            'tablecolumnwidths' => $this->tablecolumnwidths,
            'description' => $this->description,
        ]);
    }
}
