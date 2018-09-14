<?php

namespace Exceedone\Exment\Form;

use Encore\Admin\Admin;
use Encore\Admin\Form;
use Encore\Admin\Form\Field;
use Illuminate\Support\Collection;

/**
 * Class RelationModalForm.
 *
 */
class RelationModalForm
{
    const DEFAULT_KEY_NAME = '__LA_KEY__';

    //const REMOVE_FLAG_NAME = '_remove_';

    //const REMOVE_FLAG_CLASS = 'fom-removed';

    /**
     * @var string
     */
    protected $relationName;

    /**
     * NestedForm key.
     *
     * @var
     */
    protected $key;

    /**
     * Fields in form.
     *
     * @var Collection
     */
    protected $fields;

    /**
     * Original data for this field.
     *
     * @var array
     */
    protected $original = [];

    /**
     * @var \Encore\Admin\Form
     */
    protected $form;

    /**
     * Create a new NestedForm instance.
     *
     * NestedForm constructor.
     *
     * @param string $relation
     * @param null   $key
     */
    public function __construct($relation, $key = null)
    {
        $this->relationName = $relation;

        $this->key = $key;

        $this->fields = new Collection();
    }

    /**
     * Set Form.
     *
     * @param Form $form
     *
     * @return $this
     */
    public function setForm(Form $form = null)
    {
        $this->form = $form;

        return $this;
    }

    /**
     * Get form.
     *
     * @return Form
     */
    public function getForm()
    {
        return $this->form;
    }

    /**
     * Set original values for fields.
     *
     * @param array  $data
     * @param string $relatedKeyName
     *
     * @return $this
     */
    public function setOriginal($data, $relatedKeyName)
    {
        if (empty($data)) {
            return $this;
        }

        foreach ($data as $value) {
            /*
             * like $this->original[30] = [ id = 30, .....]
             */
            $this->original[$value[$relatedKeyName]] = $value;
        }

        return $this;
    }

    /**
     * Prepare for insert or update.
     *
     * @param array $input
     *
     * @return mixed
     */
    public function prepare($input)
    {
        foreach ($input as $key => $record) {
            $this->setFieldOriginalValue($key);
            $input[$key] = $this->prepareRecord($record);
        }

        return $input;
    }

    /**
     * Set original data for each field.
     *
     * @param string $key
     *
     * @return void
     */
    protected function setFieldOriginalValue($key)
    {
        $values = [];
        if (array_key_exists($key, $this->original)) {
            $values = $this->original[$key];
        }
        $this->fields->each(function (Field $field) use ($values) {
            $field->setOriginal($values);
        });
    }

    /**
     * Do prepare work before store and update.
     *
     * @param array $record
     *
     * @return array
     */
    protected function prepareRecord($record)
    {
        //if ($record[static::REMOVE_FLAG_NAME] == 1) {
        //    return $record;
        //}

        $prepared = [];

        /* @var Field $field */
        foreach ($this->fields as $field) {
            $columns = $field->column();

            $value = $this->fetchColumnValue($record, $columns);

            if (is_null($value)) {
                continue;
            }

            if (method_exists($field, 'prepare')) {
                $value = $field->prepare($value);
            }

            if (($field instanceof \Encore\Admin\Form\Field\Hidden) || $value != $field->original()) {
                if (is_array($columns)) {
                    foreach ($columns as $name => $column) {
                        array_set($prepared, $column, $value[$name]);
                    }
                } elseif (is_string($columns)) {
                    array_set($prepared, $columns, $value);
                }
            }
        }

        //$prepared[static::REMOVE_FLAG_NAME] = $record[static::REMOVE_FLAG_NAME];

        return $prepared;
    }

    /**
     * Fetch value in input data by column name.
     *
     * @param array        $data
     * @param string|array $columns
     *
     * @return array|mixed
     */
    protected function fetchColumnValue($data, $columns)
    {
        if (is_string($columns)) {
            return array_get($data, $columns);
        }

        if (is_array($columns)) {
            $value = [];
            foreach ($columns as $name => $column) {
                if (!array_has($data, $column)) {
                    continue;
                }
                $value[$name] = array_get($data, $column);
            }

            return $value;
        }
    }

    /**
     * @param Field $field
     *
     * @return $this
     */
    public function pushField(Field $field)
    {
        $this->fields->push($field);

        return $this;
    }

    /**
     * Get fields of this form.
     *
     * @return Collection
     */
    public function fields()
    {
        return $this->fields;
    }

    /**
     * Fill data to all fields in form.
     *
     * @param array $data
     *
     * @return $this
     */
    public function fill(array $data)
    {
        /* @var Field $field */
        foreach ($this->fields() as $field) {
            $field->fill($data);
        }

        return $this;
    }

    /**
     * Get the html and script of template.
     *
     * @return array
     */
    public function getTemplateHtmlAndScript()
    {
        $html = '';
        $scripts = [];

        /* @var Field $field */
        foreach ($this->fields() as $field) {

            //when field render, will push $script to Admin
            $html .= $field->render();

            /*
             * Get and remove the last script of Admin::$script stack.
             */
            if ($field->getScript()) {
                $scripts[] = array_pop(Admin::$script);
            }
        }

        return [$html, implode("\r\n", $scripts)];
    }

    /**
     * Set `errorKey` `elementName` `elementClass` for fields inside hasmany fields.
     *
     * @param Field $field
     *
     * @return Field
     */
    protected function formatField(Field $field)
    {
        $column = $field->column();

        $elementName = $elementClass = $errorKey = [];

        $key = $this->key ?: 'new_'.static::DEFAULT_KEY_NAME;

        if (is_array($column)) {
            foreach ($column as $k => $name) {
                $errorKey[$k] = sprintf('__modal__%s.%s.%s', $this->relationName, $key, $name);
                $elementName[$k] = sprintf('__modal__%s[%s][%s]', $this->relationName, $key, $name);
                $elementClass[$k] = ['__modal__'.$this->relationName, $name];
            }
        } else {
            $errorKey = sprintf('__modal__%s.%s.%s', $this->relationName, $key, $column);
            $elementName = sprintf('__modal__%s[%s][%s]', $this->relationName, $key, $column);
            $elementClass = ['__modal__'.$this->relationName, $column];
        }

        return $field->setErrorKey($errorKey)
            ->setElementName($elementName)
            ->setElementClass($elementClass);
    }

    /**
     * Add nested-form fields dynamically.
     *
     * @param string $method
     * @param array  $arguments
     *
     * @return mixed
     */
    public function __call($method, $arguments)
    {
        if ($className = Form::findFieldClass($method)) {
            $column = array_get($arguments, 0, '');

            /* @var Field $field */
            $field = new $className($column, array_slice($arguments, 1));

            $field->setForm($this->form);

            $field = $this->formatField($field);

            $this->pushField($field);

            return $field;
        }

        return $this;
    }
}
