<?php

namespace Exceedone\Exment\Form;

use Encore\Admin\Show\Field;
use Encore\Admin\Show as AdminShow;
use Illuminate\Support\Collection;

class Show extends AdminShow
{

    /**
     * Add a model field to show.
     *
     * @param string $name
     * @param string $label
     *
     * @return Field
     */
    public function field($name, $label = '', $column_no = 1)
    {
        return $this->addField($name, $label, $column_no);
    }

    /**
     * Add a model field to show.
     *
     * @param string $name
     * @param string $label
     *
     * @return Field
     */
    protected function addField($name, $label = '', $column_no = 1)
    {
        $field = new Field($name, $label);

        $field->setParent($this);

        //$this->overwriteExistingField($name);

        return tap($field, function ($field) use ($column_no, $name) {
            $columns = $this->fields->get($column_no) ?? new Collection();
            $columns = $columns->filter(function (Field $field) use ($name) {
                return $field->getName() != $name;
            });
            $columns->push($field);
            $this->fields->put($column_no, $columns);
        });
    }

    /**
     * Render the show panels.
     *
     * @return string
     */
    public function render()
    {
        if (is_callable($this->builder)) {
            call_user_func($this->builder, $this);
        }

        if ($this->fields->isEmpty()) {
            $this->all();
        }

        if (is_array($this->builder)) {
            $this->fields($this->builder);
        }

        $this->fields->each(function ($item, $key) {
            $item->each->setValue($this->model);
        });
        $this->relations->each->setModel($this->model);

        $data = [
            'panel'     => $this->panel->fill($this->fields),
            'relations' => $this->relations,
        ];

        return view('admin::show', $data)->render();
    }
}
