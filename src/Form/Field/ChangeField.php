<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field;
use Exceedone\Exment\Enums\ViewColumnFilterOption;
use Exceedone\Exment\Model\CustomViewFilter;

/**
 * change field. If user select other input select, change input field
 */
class ChangeField extends Field
{
    protected $view = 'exment::form.field.changefield';

    protected $field = null;

    protected function getElementClass()
    {
        if (preg_match('/(^[^\[\]]+)\[([^\[\]]+)\]\[([^\[\]]+)\]$/', $this->elementName, $array_result)) {
            array_shift($array_result);
            $array_result[1] = 'rowno-'.$array_result[1];
            return $array_result;
        }
        return [];
    }

    /**
     * Set options.
     *
     * @param array|callable|string $options
     *
     * @return $this|mixed
     */
    public function options($options = [])
    {
        if ($options instanceof Arrayable) {
            $options = $options->toArray();
        }

        if (is_callable($options)) {
            $this->options = $options;
        } else {
            $this->options = (array) $options;
        }

        return $this;
    }
    public function render()
    {
        // $viewClass = $this->getViewElementClasses();

        $view_filter_condition = $this->data['view_filter_condition'];
        $view_column_target = $this->data['view_column_target'];

        $value_type = ViewColumnFilterOption::VIEW_COLUMN_VALUE_TYPE($view_filter_condition);

        if ($value_type == 'none') {
            return parent::render();
        }

        // get column item
        $column_item = CustomViewFilter::getColumnItem($view_column_target)
            ->options([
                'view_column_target' => true,
        ]);

        $field = $column_item->getFilterField($value_type);

        if (isset($field)) {
            $field->setWidth(12, 0)->setLabelClass(['hidden']);
            $field->value($this->value);
            $field->setElementName($this->elementName)
                ->setErrorKey($this->getErrorKey())
                ->setElementClass($this->getElementClass());
            $view = $field->render();
            $this->script = $field->getScript();
            return $view;
        }
    }
}
