<?php

namespace Exceedone\Exment\Services\DataImportExport\Actions\Export;

use Exceedone\Exment\Services\DataImportExport\Providers\Export;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Enums\RelationType;

class CustomTableAction extends ExportActionBase implements ActionInterface
{
    /**
     * target custom table
     */
    protected $custom_table;

    /**
     * custom_table's relations
     */
    protected $relations;

    /**
     * laravel-admin grid
     */
    protected $grid;

    /**
     * Whether appending setting
     *
     * @var bool
     */
    protected $add_setting = true;

    /**
     * Whether appending relation
     *
     * @var bool
     */
    protected $add_relation = true;

    public function __construct($args = [])
    {
        $this->custom_table = array_get($args, 'custom_table');

        // get relations
        $this->relations = CustomRelation::getRelationsByParent($this->custom_table);

        $this->grid = array_get($args, 'grid');

        $this->add_setting = array_get($args, 'add_setting', true);
        $this->add_relation = array_get($args, 'add_relation', true);
    }

    public function datalist()
    {
        $providers = [];

        // get default data
        $providers[] = new Export\DefaultTableProvider([
            'custom_table' => $this->custom_table,
            'grid' => $this->grid
        ]);


        if (boolval($this->add_relation)) {
            foreach ($this->relations as $relation) {
                // if n:n, create as RelationPivotTable
                if ($relation->relation_type == RelationType::MANY_TO_MANY) {
                    $providers[] = new Export\RelationPivotTableProvider(
                        [
                            'relation' => $relation,
                            'grid' => $this->grid
                        ]
                    );
                } else {
                    $providers[] = new Export\DefaultTableProvider(
                        [
                            'custom_table' => $relation->child_custom_table,
                            'grid' => $this->grid,
                            'parent_table' => $this->custom_table->table_name,
                        ]
                    );
                }
            }
        }

        if (boolval($this->add_setting)) {
            $providers[] = new Export\DefaultTableSettingProvider(
                [
                    'custom_table' => $this->custom_table,
                ]
            );
        }

        $datalist = [];
        foreach ($providers as $provider) {
            if (!$provider->isOutput()) {
                continue;
            }

            $datalist[] = ['name' => $provider->name(), 'outputs' => $provider->data()];
            $this->count .= $provider->getCount();
        }

        return $datalist;
    }

    public function filebasename()
    {
        return $this->custom_table->table_view_name;
    }
}
