<?php

namespace Exceedone\Exment\Form\Field;

/**
 * Class HasMany.
 */
class HasManyJsonTable extends HasManyTable
{
    use HasManyJsonTrait{
        HasManyJsonTrait::getKeyName as getKeyNameTrait;
        HasManyJsonTrait::prepare as prepareTrait;
        HasManyJsonTrait::buildRelatedForms as buildRelatedFormsTrait;
        HasManyJsonTrait::buildNestedForm as buildNestedFormTrait;
    }

    /**
     * Get the HasMany relation key name.
     *
     * @return string
     */
    protected function getKeyName()
    {
        return $this->getKeyNameTrait();
    }


    public function prepare($input)
    {
        return $this->prepareTrait($input);
    }

    /**
     * Build Nested form for json data.
     *
     * @throws \Exception
     *
     * @return array
     */
    protected function buildRelatedForms()
    {
        return $this->buildRelatedFormsTrait();
    }


    protected function buildNestedForm($column, \Closure $builder, $key = null, $index = null)
    {
        return $this->buildNestedFormTrait($column, $builder, $key, $index);
    }

    protected function getParentRenderClass()
    {
        return get_parent_class(get_parent_class(get_parent_class(get_parent_class($this))));
    }
}
