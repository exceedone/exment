<?php

namespace Exceedone\Exment\DataItems\Show;

use Encore\Admin\Form;
use Exceedone\Exment\Model\PublicForm;
use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Form\Show as PublicShow;
use Exceedone\Exment\ColumnItems\ItemInterface;
use Illuminate\Database\Eloquent\Relations;

class PublicFormShow extends DefaultShow
{
    protected static $showClassName = PublicShow\PublicShow::class;
    
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
     * @return array
     */
    public function getChildRelationShows(array $relationInputs)
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
            $relationShowPanel = new PublicShow\PublicShowRelation();
            $relationShowPanel->setTitle($custom_form_block->getRelationInfo()[2]);

            foreach ($custom_values as $info) {
                $custom_value = $info['custom_value'];
                // Create child panel
                $childShow = new PublicShow\PublicShowChild($custom_value, function ($show) use ($custom_form_block) {
                    $this->setByCustomFormBlock($show, $custom_form_block);
                });
                $relationShowPanel->addChildren($childShow);
            }
            $result[] = $relationShowPanel;
        }

        return $result;
    }

    /**
     * Get relation models
     *
     * @param array $relationInputs
     * @return voidarray
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
                    ];
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

        return parent::setColumnItemOption($column_item, $form_column);
    }
}
