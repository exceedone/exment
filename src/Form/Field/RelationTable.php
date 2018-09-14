<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Admin;
use Encore\Admin\Form;
use Encore\Admin\Form\Field;
use Exceedone\Exment\Form\RelationTableForm;
use Exceedone\Exment\Form\RelationModalForm;
use Illuminate\Database\Eloquent\Relations\HasMany as Relation;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Class RelationTable.
 */
class RelationTable extends Field
{
    /**
     * Relation name.
     *
     * @var string
     */
    protected $relationName = '';

    /**
     * relatedPivotKeyName MorphToMany
     * @var mixed
     */
    protected $relatedPivotKeyName = '';

    /**
     * Form builder.
     *
     * @var \Closure
     */
    protected $builder = null;

    /**
     * Modal builder.
     *
     * @var \Closure
     */
    protected $modal_builder = null;

    /**
     * Form data.
     *
     * @var array
     */
    protected $value = [];

    /**
     * Table header column labels
     * @var mixed
     */
    protected $header_columns = [];

    /**
     * View Mode.
     *
     * Supports `default` and `tab` currently.
     *
     * @var string
     */
    protected $viewMode = 'default';

    /**
     * Available views for RelationTable field.
     *
     * @var array
     */
    protected $views = [
        'default' => 'exment::form.field.relation-table.relation-table',
        'tab'     => 'exment::form.field.relation-table.relation-table',
    ];
    /**
     * Create a new RelationTable field instance.
     *
     * @param $relationName
     * @param array $arguments
     */
    public function __construct($relationName, $arguments = [])
    {
        $this->relationName = $relationName;

        $this->column = $relationName;

        if (count($arguments) == 2) {
            $this->label = $this->formatLabel();
            list($this->builder, $this->modal_builder) = $arguments;
        }

        if (count($arguments) == 3) {
            list($this->label, $this->builder, $this->modal_builder) = $arguments;
        }
    }

    /**
     * Get validator for this field.
     *
     * @param array $input
     *
     * @return bool|Validator
     */
    public function getValidator(array $input)
    {
        if (!array_key_exists($this->column, $input)) {
            return false;
        }

        $input = array_only($input, $this->column);

        $form = $this->buildRelationTableForm($this->column, $this->builder);

        $rules = $attributes = [];

        /* @var Field $field */
        foreach ($form->fields() as $field) {
            if (!$fieldRules = $field->getRules()) {
                continue;
            }

            $column = $field->column();

            if (is_array($column)) {
                foreach ($column as $key => $name) {
                    $rules[$name.$key] = $fieldRules;
                }

                $this->resetInputKey($input, $column);
            } else {
                $rules[$column] = $fieldRules;
            }

            $attributes = array_merge(
                $attributes,
                $this->formatValidationAttribute($input, $field->label(), $column)
            );
        }

        //array_forget($rules, RelationTableForm::REMOVE_FLAG_NAME);

        if (empty($rules)) {
            return false;
        }

        $newRules = [];

        foreach ($rules as $column => $rule) {
            foreach (array_keys($input[$this->column]) as $key) {
                $newRules["{$this->column}.$key.$column"] = $rule;
            }
        }

        return Validator::make($input, $newRules, $this->validationMessages, $attributes);
    }

    /**
     * Format validation attributes.
     *
     * @param array  $input
     * @param string $label
     * @param string $column
     *
     * @return array
     */
    protected function formatValidationAttribute($input, $label, $column)
    {
        $new = $attributes = [];

        if (is_array($column)) {
            foreach ($column as $index => $col) {
                $new[$col.$index] = $col;
            }
        }

        foreach (array_keys(array_dot($input)) as $key) {
            if (is_string($column)) {
                if (Str::endsWith($key, ".$column")) {
                    $attributes[$key] = $label;
                }
            } else {
                foreach ($new as $k => $val) {
                    if (Str::endsWith($key, ".$k")) {
                        $attributes[$key] = $label."[$val]";
                    }
                }
            }
        }

        return $attributes;
    }

    /**
     * Reset input key for validation.
     *
     * @param array $input
     * @param array $column $column is the column name array set
     *
     * @return void.
     */
    protected function resetInputKey(array &$input, array $column)
    {
        /**
         * flip the column name array set.
         *
         * for example, for the DateRange, the column like as below
         *
         * ["start" => "created_at", "end" => "updated_at"]
         *
         * to:
         *
         * [ "created_at" => "start", "updated_at" => "end" ]
         */
        $column = array_flip($column);

        /**
         * $this->column is the inputs array's node name, default is the relation name.
         *
         * So... $input[$this->column] is the data of this column's inputs data
         *
         * in the RelationTable relation, has many data/field set, $set is field set in the below
         */
        foreach ($input[$this->column] as $index => $set) {

            /*
             * foreach the field set to find the corresponding $column
             */
            foreach ($set as $name => $value) {
                /*
                 * if doesn't have column name, continue to the next loop
                 */
                if (!array_key_exists($name, $column)) {
                    continue;
                }

                /**
                 * example:  $newKey = created_atstart.
                 *
                 * Σ( ° △ °|||)︴
                 *
                 * I don't know why a form need range input? Only can imagine is for range search....
                 */
                $newKey = $name.$column[$name];

                /*
                 * set new key
                 */
                array_set($input, "{$this->column}.$index.$newKey", $value);
                /*
                 * forget the old key and value
                 */
                array_forget($input, "{$this->column}.$index.$name");
            }
        }
    }

    /**
     * Prepare input data for insert or update.
     *
     * @param array $input
     *
     * @return array
     */
    public function prepare($input)
    {
        $form = $this->buildRelationTableForm($this->column, $this->builder);

        return $form->setOriginal($this->original, $this->getKeyName())->prepare($input);
    }

    /**
     * Build a RelationTableForm.
     *
     * @param string   $column
     * @param \Closure $builder
     * @param null     $key
     *
     * @return RelationTableForm
     */
    protected function buildRelationTableForm($column, \Closure $builder, $key = null)
    {
        $form = new RelationTableForm($column, $key);

        $this->relatedPivotKeyName = $this->form->model()->{$this->relationName}()->getRelatedPivotKeyName();

        $form->setForm($this->form);

        call_user_func($builder, $form);

        $form->hidden($this->relatedPivotKeyName);

        //$form->hidden(RelationTableForm::REMOVE_FLAG_NAME)->default(0)->addElementClass(RelationTableForm::REMOVE_FLAG_CLASS);

        return $form;
    }

    /**
     * Build a buildRelationModalForm.
     *
     * @param string   $column
     * @param \Closure $builder
     * @param null     $key
     *
     * @return RelationTableForm
     */
    protected function buildRelationModalForm($column, \Closure $modal_builder, $key = null)
    {
        $form = new RelationModalForm($column, $key);

        $form->setForm($this->form);

        call_user_func($modal_builder, $form);

        return $form;
    }

    /**
     * Get the RelationTable relation key name.
     *
     * @return string
     */
    protected function getKeyName()
    {
        if (is_null($this->form)) {
            return;
        }

        return $this->form->model()->{$this->relationName}()->getRelated()->getKeyName();
    }

    /**
     * Set view mode.
     *
     * @param string $mode currently support `tab` mode.
     *
     * @return $this
     *
     * @author Edwin Hui
     */
    public function mode($mode)
    {
        $this->viewMode = $mode;

        return $this;
    }

    /**
     * Use tab mode to showing RelationTable field.
     *
     * @return RelationTable
     */
    public function useTab()
    {
        return $this->mode('tab');
    }

    /**
     * Build Nested form for related data.
     *
     * @throws \Exception
     *
     * @return array
     */
    protected function buildRelatedForms()
    {
        if (is_null($this->form)) {
            return [];
        }

        $model = $this->form->model();

        $relation = call_user_func([$model, $this->relationName]);

        if (!$relation instanceof Relation && !$relation instanceof MorphMany && !$relation instanceof MorphToMany) {
            throw new \Exception('RelationTable field must be a RelationTable or MorphMany  or MorphToMany relation.');
        }

        $forms = [];

        /*
         * If redirect from `exception` or `validation error` page.
         *
         * Then get form data from session flash.
         *
         * Else get data from database.
         */
        if ($values = old($this->column)) {
            foreach ($values as $key => $data) {
                //if ($data[RelationTableForm::REMOVE_FLAG_NAME] == 1) {
                //    continue;
                //}

                $forms[$key] = $this->buildRelationTableForm($this->column, $this->builder, $key)
                    ->fill($data);
            }
        } else {
            foreach ($this->value as $data) {
                $key = array_get($data, $relation->getRelated()->getKeyName());

                $forms[$key] = $this->buildRelationTableForm($this->column, $this->builder, $key)
                    ->fill($data);
            }
        }

        return $forms;
    }

    /**
     * Setup script for this field in different view mode.
     *
     * @param string $script
     *
     * @return void
     */
    protected function setupScript($script)
    {
        $method = 'setupScriptFor'.ucfirst($this->viewMode).'View';

        call_user_func([$this, $method], $script);
    }

    /**
     * set table items.
     * table shows only "tableitem" field.
     * "hidden" field ignore.
     */
    protected function setTableItems($form){
        foreach($form->fields() as $field){
            if($field instanceof \Encore\Admin\Form\Field\Hidden){
                continue;
            }
            $this->header_columns[$field->column] = $field->label();
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
        $removeClass = RelationTableForm::REMOVE_FLAG_CLASS;
        $defaultKey = RelationTableForm::DEFAULT_KEY_NAME;

        // 接続先取得
        $relation = call_user_func([$this->form->model(), $this->relationName]);

        $api_url = admin_url("api/".getEndpointName($relation->getRelated()));

        $script = <<<EOT
    $('#relation-modal-{$this->column} .modal-footer button').off('click').on('click', function(event) {
      var button = $(event.target); // The clicked button
      var modal = $(this).closest('.modal');
      var table = $("#relation-table-{$this->column} table");
      var id = modal.find('.id').val();
      if($(button).hasClass('setting')){
          $.ajax({
                url:'$api_url' + '/' + id,
                type:'POST',
                data:{
                    dot:1,
                    _token:LA.token
                }
          }).done((data) => {
              var tds = [];
              var trs = table.find('thead tr th');
              for(var i = 0; i < trs.length; i++){
                  var tr = trs.eq(i);
                  var key = tr.data('field-name');
                  if(data[key]){
                    tds.push('<td>' + data[key] + '</td>');
                  }
              }

              var tpl = $('#relation-table-template-action-{$this->column}');
              var html = tpl.html().replace(/{$defaultKey}/g, id);
              tds.push(html);

              var tr = $('<tr/>', {html: tds.join('')});
              tr.find('.{$this->relatedPivotKeyName}').val(id);
              table.find('tbody').append(tr);

              modal.modal('hide');
          });
      }

	    $(modal).off('hidden.bs.modal').on('hidden.bs.modal', function () {
		    $(modal).find("input,select").val('');
	    });
    });
EOT;
        Admin::script($script);

        $deleteConfirm = trans('admin.delete_confirm');
        $confirm = trans('admin.confirm');
        $cancel = trans('admin.cancel');

        $script = <<<SCRIPT
$(document).off('click', '.grid-row-delete').on('click', '.grid-row-delete', {}, function() {
    var tr = $(this).closest('tr');
    swal({
      title: "$deleteConfirm",
      type: "warning",
      showCancelButton: true,
      confirmButtonColor: "#DD6B55",
      confirmButtonText: "$confirm",
      closeOnConfirm: true,
      cancelButtonText: "$cancel"
    },
    function(){
        tr.remove();
    });
});

SCRIPT;

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
        $removeClass = RelationTableForm::REMOVE_FLAG_CLASS;
        $defaultKey = RelationTableForm::DEFAULT_KEY_NAME;

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
     * Render the `RelationTable` field.
     *
     * @throws \Exception
     *
     * @return \Illuminate\View\View
     */
    public function render()
    {
        // create table header
        $form = $this->buildRelationTableForm($this->column, $this->builder);

        // set table headers
        $this->setTableItems($form);

        // specify a view to render.
        $this->view = $this->views[$this->viewMode];

        list($template, $script) = $this->buildRelationTableForm($this->column, $this->builder)
            ->getTemplateHtmlAndScript();

        $this->setupScript($script);

        return parent::render()->with([
            'forms'        => $this->buildRelatedForms(),
            'modal_form'        => $this->buildRelationModalForm($this->column, $this->modal_builder),
            'header_columns'=> $this->header_columns,
            'template'     => $template,
            'relationName' => $this->relationName,
        ]);
    }
}
