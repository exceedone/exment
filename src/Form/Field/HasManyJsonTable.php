<?php

namespace Exceedone\Exment\Form\Field;

use Illuminate\Support\Arr;
use Illuminate\Validation\Validator;

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
     * Validate the rows as posted. As soon as a cell with rules posts an array (a multiple
     * select), HasMany validates "column.key.cell0", "column.key.cell1"... in place of the
     * rows: keys the validator reads as nested, matching no rule, so no cell of any row was
     * checked (e.g. a required or a distinct rule on another cell passed).
     *
     * @param array<mixed> $input
     * @return \Illuminate\Contracts\Validation\Validator|bool
     */
    public function getValidator(array $input)
    {
        $validator = parent::getValidator($input);
        if ($validator instanceof Validator && $this->hasArrayCell($input)) {
            $validator->setData(Arr::only($input, $this->column));
        }
        return $validator;
    }

    /**
     * @param array<mixed> $input
     */
    protected function hasArrayCell(array $input): bool
    {
        foreach (Arr::only($input, $this->column) as $rows) {
            foreach (is_array($rows) ? $rows : [] as $row) {
                foreach (is_array($row) ? $row : [] as $cell) {
                    if (is_array($cell)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Build Nested form for json data.
     *
     * @throws \Exception
     *
     * @return array
     */
    // @phpstan-ignore-next-line
    protected function buildRelatedForms()
    {
        return $this->buildRelatedFormsTrait();
    }


    protected function buildNestedForm($column, \Closure $builder, $key = null, $index = null)
    {
        return $this->buildNestedFormTrait($column, $builder, $key, $index);
    }

    // @phpstan-ignore-next-line
    protected function getParentRenderClass()
    {
        return get_parent_class(get_parent_class(get_parent_class(get_parent_class($this))));
    }
}
