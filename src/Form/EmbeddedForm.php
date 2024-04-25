<?php

namespace Exceedone\Exment\Form;

use Encore\Admin\Form\Field;
use Encore\Admin\Form\EmbeddedForm as EmbeddedFormBase;

/**
 * Class EmbeddedForm.
 *
 * @method Field\Text           text($column, $label = '')
 * @method Field\Checkbox       checkbox($column, $label = '')
 * @method Field\Radio          radio($column, $label = '')
 * @method Field\Select         select($column, $label = '')
 * @method Field\MultipleSelect multipleSelect($column, $label = '')
 * @method Field\Textarea       textarea($column, $label = '')
 * @method Field\Hidden         hidden($column, $label = '')
 * @method Field\Id             id($column, $label = '')
 * @method Field\Ip             ip($column, $label = '')
 * @method Field\Url            url($column, $label = '')
 * @method Field\Color          color($column, $label = '')
 * @method Field\Email          email($column, $label = '')
 * @method Field\Mobile         mobile($column, $label = '')
 * @method Field\Slider         slider($column, $label = '')
 * @method Field\Map            map($latitude, $longitude, $label = '')
 * @method Field\Editor         editor($column, $label = '')
 * @method Field\File           file($column, $label = '')
 * @method Field\Image          image($column, $label = '')
 * @method Field\Date           date($column, $label = '')
 * @method Field\Datetime       datetime($column, $label = '')
 * @method Field\Time           time($column, $label = '')
 * @method Field\Year           year($column, $label = '')
 * @method Field\Month          month($column, $label = '')
 * @method Field\DateRange      dateRange($start, $end, $label = '')
 * @method Field\DateTimeRange  datetimeRange($start, $end, $label = '')
 * @method Field\TimeRange      timeRange($start, $end, $label = '')
 * @method Field\Number         number($column, $label = '')
 * @method Field\Currency       currency($column, $label = '')
 * @method Field\HasMany        hasMany($relationName, $callback)
 * @method Field\SwitchField    switch($column, $label = '')
 * @method Field\Display        display($column, $label = '')
 * @method Field\Rate           rate($column, $label = '')
 * @method Field\Divide         divider()
 * @method Field\Password       password($column, $label = '')
 * @method Field\Decimal        decimal($column, $label = '')
 * @method Field\Html           html($html, $label = '')
 * @method Field\Tags           tags($column, $label = '')
 * @method Field\Icon           icon($column, $label = '')
 * @method Field\Embeds         embeds($column, $label = '')
 */
class EmbeddedForm extends EmbeddedFormBase
{
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
     * Push field and set for grid
     *
     * @param Field $field
     * @param array $options
     * @return $this
     */
    public function pushFieldAndOption(Field $field, array $options)
    {
        $this->pushField($field);

        $this->fieldAndOptions[] = [
            'field' => $field,
            'options' => $options,
        ];

        return $this;
    }

    /**
     * Get field and options
     *
     * @return array
     */
    public function getFieldAndOptions()
    {
        return $this->fieldAndOptions;
    }

    /**
     * Prepare for insert or update.
     *
     * @param array|null $input
     *
     * @return mixed
     */
    public function prepare($input, bool $asConfirm = false)
    {
        if (is_null($input)) {
            return $input;
        }

        return parent::prepare($input, $asConfirm);
    }
}
