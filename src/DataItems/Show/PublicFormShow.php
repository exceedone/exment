<?php

namespace Exceedone\Exment\DataItems\Show;

use Encore\Admin\Form;
use Exceedone\Exment\Model\PublicForm;
use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Form\Show as PublicShow;
use Encore\Admin\Show\Field as ShowField;
use Exceedone\Exment\ColumnItems\ItemInterface;
use Illuminate\Database\Eloquent\Relations;

class PublicFormShow extends DefaultShow
{
    protected static $showClassName = PublicShow\PublicShow::class;

    /**
     * @var PublicForm
     */
    protected $public_form;

    /**
     * Set public Form
     *
     * @param  PublicForm  $public_form  Public Form
     *
     * @return  self
     */
    public function setPublicForm(PublicForm $public_form)
    {
        $this->public_form = $public_form;

        return $this;
    }


    /**
     * Get child relation's show item.
     *
     * @param array $relationInputs
     * @param mixed $show default show's model. Use for n:n relation.
     * @return array
     */
    public function getChildRelationShows(array $relationInputs, $show)
    {
        // get relations
        $relations = $this->getRelationModels($relationInputs);

        $result = [];
        foreach ($relations as $custom_values) {
            if (empty($custom_values)) {
                continue;
            }
            $custom_form_block = $custom_values[0]['custom_form_block'];

            // Create show panel for relation
            $relationInfo = $custom_form_block->getRelationInfo();

            foreach ($custom_values as $info) {
                $custom_value = $info['custom_value'];

                // add field if n:n
                if ($info['relation_type'] == RelationType::MANY_TO_MANY) {
                    $field = new ShowField($relationInfo[1], $relationInfo[2]);
                    $field->as(function ($v) use ($custom_value) {
                        // $custom_value is collection
                        return $custom_value->filter()->map(function ($custom_value) {
                            return $custom_value->getLabel();
                        })->implode(exmtrans('common.separate_word'));
                    });

                    $show->addFieldAndOption($field, [
                        'row' => 999, // ToDo: the best way for getting row no.
                        'column' => 1,
                        'width' => 4,
                        'calcWidth' => false,
                    ]);
                } else {
                    $relationShowPanel = new PublicShow\PublicShowRelation();
                    $relationShowPanel->setTitle($relationInfo[2]);
                    // Create child panel if 1:n
                    $childShow = new PublicShow\PublicShowChild($custom_value, function ($show) use ($custom_form_block) {
                        $this->setByCustomFormBlock($show, $custom_form_block);
                    });
                    $relationShowPanel->addChildren($childShow);

                    $result[] = $relationShowPanel;
                }
            }
        }

        return $result;
    }

    /**
     * Get relation models
     *
     * @param array $relationInputs
     * @return array
     */
    protected function getRelationModels(array $relationInputs)
    {
        $relations = [];
        foreach ($relationInputs as $column => $value) {
            if (!method_exists($this->custom_value, $column)) {
                continue;
            }

            $relation = call_user_func([$this->custom_value, $column]);

            if ($relation instanceof Relations\Relation) {
                // get custom form block
                $custom_form_block = $this->custom_form->custom_form_blocks_cache->first(function ($custom_form_block) use ($column) {
                    $info = $custom_form_block->getRelationInfo();
                    return isMatchString($info[1], $column);
                });
                if (!$custom_form_block) {
                    continue;
                }

                // set as N:N relation
                if ($relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsToMany) {
                    $relations[$column][] = [
                        'custom_form_block' => $custom_form_block,
                        'custom_value' => (clone $relation->getRelated())->query()->findMany($value),
                        'relation_type' => RelationType::MANY_TO_MANY,
                    ];
                } else {
                    // create child model
                    foreach ($value as $v) {
                        if (array_get($v, Form::REMOVE_FLAG_NAME) == 1) {
                            continue;
                        }

                        $model = clone $relation->getRelated();
                        $model->fill($v);

                        $relations[$column][] = [
                            'custom_form_block' => $custom_form_block,
                            'custom_value' => $model,
                            'relation_type' => RelationType::ONE_TO_MANY,
                        ];
                    }
                }
            }
        }

        return $relations;
    }


    /**
     * Set ColumnItem's option to column item
     *
     * @param ItemInterface $column_item
     * @return void
     */
    protected function setColumnItemOption(ItemInterface $column_item, ?CustomFormColumn $form_column = null)
    {
        $column_item->options(['public_form' => $this->public_form]);
        $column_item->options(['as_confirm' => true]);

        parent::setColumnItemOption($column_item, $form_column);
    }
}
