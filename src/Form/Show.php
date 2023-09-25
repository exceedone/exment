<?php

namespace Exceedone\Exment\Form;

use Encore\Admin\Show\Field;
use Encore\Admin\Show as AdminShow;
use Exceedone\Exment\Form\Field\FieldGroupTrait;
use Exceedone\Exment\Form\Show\GridShowPanel;

class Show extends AdminShow
{
    use FieldGroupTrait;

    /**
     * Initialize panel.
     */
    protected function initPanel()
    {
        $this->panel = new GridShowPanel($this);
    }

    /**
     * Whether grid shows
     *
     * @var boolean
     */
    protected $gridShows = false;

    /**
     * field option
     *
     * [
     *     'options' => [], // Set row no, column no, width
     *     'field' => AdminField, // Set adminfield
     * ]
     * @var array
     */
    protected $fieldAndOptions = [];


    /**
     * Set as gridShows
     *
     * @return $this
     */
    public function gridShows()
    {
        $this->gridShows = true;
        return $this;
    }

    /**
     * get max row
     *
     * @return int max row
     */
    public function getMaxRow()
    {
        return collect($this->fieldAndOptions)->map(function ($fieldOption) {
            return array_get($fieldOption, 'options.row', 0);
        })->max();
    }

    /**
     * Push field and set for grid
     *
     * @param Field $field
     * @param array $options
     * @return Field
     */
    public function addFieldAndOption($field, array $options)
    {
        $field = $this->addField($field);

        $this->fieldAndOptions[] = [
            'field' => $field,
            'options' => $options,
        ];

        return $field;
    }

    protected function renderView($data)
    {
        if (!$this->gridShows) {
            return parent::renderView($data);
        }

        ////// for grid column
        // sort by option row and column
        $fieldGroups = $this->convertRowColumnGroups($this->fieldAndOptions);
        $this->panel->setData('fieldGroups', $fieldGroups)
            ->setData('gridShows', true);
        return parent::renderView($data);
    }
}
