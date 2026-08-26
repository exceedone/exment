<?php

namespace Exceedone\Exment\Services\Migration\Concerns;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Services\Migration\Sources\FileSource;

/**
 * Working out what a choice column should offer.
 *
 * Taken from the data rather than guessed: either from a master list the
 * source publishes, or from the values the records themselves contain.
 */
trait CollectsChoices
{
    /**
     * What a choice column should offer, taken from the data rather than
     * guessed.
     *
     * Two ways in. A column can name a master stream, which is right when the
     * source has one - every status a project defines, including the ones no
     * ticket currently sits in. Otherwise the values present in the records
     * themselves are collected, which is the best that can be done and is
     * exactly what a csv export gives you.
     *
     * @param FileSource $file
     * @return array<string, array<string, string>>
     */
    protected function collectChoices(FileSource $file): array
    {
        $choices = [];

        foreach ((array)array_get($this->preset, 'streams', []) as $name => $stream) {
            foreach ((array)array_get($stream, 'columns', []) as $column => $definition) {
                $definition = (array)$definition;

                if (array_get($definition, 'type') != ColumnType::SELECT_VALTEXT) {
                    continue;
                }
                if (!empty(array_get($definition, 'choices'))) {
                    continue;
                }

                $key = $name . '.' . $column;

                if ($from = array_get($definition, 'choices_from')) {
                    $choices[$key] = $this->choicesFromStream($file, (array)$from);
                    continue;
                }

                $choices[$key] = $this->choicesFromRecords($file, strval($name), $definition);
            }
        }

        return $choices;
    }

    /**
     * @param FileSource $file
     * @param array<string, mixed> $from
     * @return array<string, string>
     */
    protected function choicesFromStream(FileSource $file, array $from): array
    {
        $stream = strval(array_get($from, 'stream'));
        $valuePath = strval(array_get($from, 'value', 'id'));
        $labelPath = strval(array_get($from, 'label', 'name'));

        $list = [];

        foreach ($file->fetch($stream) as $row) {
            $value = $this->pick($row, $valuePath);
            if (is_nullorempty($value)) {
                continue;
            }
            $label = $this->pick($row, $labelPath);
            $list[strval($value)] = is_nullorempty($label) ? strval($value) : strval($label);
        }

        return $list;
    }

    /**
     * @param FileSource $file
     * @param string $stream
     * @param array<string, mixed> $definition
     * @return array<string, string>
     */
    protected function choicesFromRecords(FileSource $file, string $stream, array $definition): array
    {
        $path = strval(array_get($definition, 'from', ''));
        $labelPath = array_get($definition, 'choice_label');

        $list = [];

        foreach ($file->fetch($stream) as $row) {
            $value = $this->pick($row, $path);
            if (is_nullorempty($value) || is_array($value)) {
                continue;
            }
            $label = $labelPath ? $this->pick($row, strval($labelPath)) : null;
            $list[strval($value)] = is_nullorempty($label) ? strval($value) : strval($label);
        }

        ksort($list);

        return $list;
    }
}
