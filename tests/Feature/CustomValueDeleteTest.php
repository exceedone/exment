<?php

namespace Exceedone\Exment\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Exceedone\Exment\Model;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\FileType;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use Exceedone\Exment\Tests\PluginTestTrait;
use Exceedone\Exment\Revisionable\Revision;

/**
 * Custom value delete test.
 * Soft delete, hard delete, always hard delete.
 * Check target: file, document, storages, and revisions
 */
class CustomValueDeleteTest extends FeatureTestBase
{
    use TestTrait;
    use PluginTestTrait;

    protected function init(bool $isDeleteHardForce)
    {
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));

        \Config::set('exment.delete_force_custom_value', $isDeleteHardForce);
    }


    /**
     * Initialize test data
     *
     * @return CustomValue
     */
    protected function initTestData(CustomTable $custom_table, CustomColumn $custom_column, \Closure $setValueCallback = null)
    {
        $custom_value = $custom_table->getValueModel();

        $file = Model\File::storeAs(FileType::CUSTOM_VALUE_COLUMN, TestDefine::FILE_TESTSTRING, $custom_table->table_name, 'test.txt');

        $custom_value->setValue('file', $file->path)
            ->setValue('text', make_uuid());

        if ($setValueCallback) {
            \call_user_func($setValueCallback, $custom_value);
        }
        $custom_value->save();

        $file->saveCustomValue($custom_value->id, $custom_column, $custom_table);

        // set 2 documents
        foreach ([0, 1] as $i) {
            $fileName = "test{$i}.txt";
            Model\File::storeAs(FileType::CUSTOM_VALUE_DOCUMENT, TestDefine::FILE_TESTSTRING, $custom_table->table_name, $fileName)
                ->saveCustomValue($custom_value->id, null, $custom_table)
                ->saveDocumentModel($custom_value, $fileName);
        }

        return $custom_value;
    }


    /**
     * Initialize test data
     *
     * @return CustomValue
     */
    protected function initTestDataDefault()
    {
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $custom_column = CustomColumn::getEloquent('file', $custom_table);

        return $this->initTestData($custom_table, $custom_column);
    }


    /**
     * Initialize test data
     *
     * @return array
     */
    protected function initTestDataRelation1n()
    {
        $custom_table_parent = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE);
        $custom_column_parent = CustomColumn::getEloquent('file', $custom_table_parent);

        $custom_value = $this->initTestData($custom_table_parent, $custom_column_parent);

        // init children
        $children = [];
        foreach (range(1, 10) as $index) {
            $custom_table_child = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE);
            $custom_column_child = CustomColumn::getEloquent('file', $custom_table_child);

            $children[] = $this->initTestData($custom_table_child, $custom_column_child, function ($child_custom_value) use ($custom_table_parent, $custom_value) {
                $child_custom_value->parent_id = $custom_value->id;
                $child_custom_value->parent_type = $custom_table_parent->table_name;
            });
        }

        return [$custom_value, $children];
    }


    public function testSoftDelete()
    {
        $this->init(false);
        $custom_value = $this->initTestDataDefault();

        // get custom value info
        $info = $this->getCustomValueInfo($custom_value->custom_table, $custom_value->id);
        $this->assertMatch(count($info['files']), 3);
        $this->assertMatch(count($info['documents']), 2);
        $this->assertMatch(count($info['revisions']), 2); // auto complete execute, so count is 2

        // Soft delete ----------------------------------------------------
        $custom_value->delete();
        // check deleted
        $infoDeleted = $this->getCustomValueInfo($custom_value->custom_table, $custom_value->id);
        $this->assertMatch(count($infoDeleted['files']), 3); // not deleted
        $this->assertMatch(count($infoDeleted['documents']), 2); // not deleted
        $this->assertMatch(count($infoDeleted['storages']), count($infoDeleted['storages'])); // not deleted
        $this->assertMatch(count($infoDeleted['revisions']), 3);

        // Force delete ----------------------------------------------------
        $custom_value->forceDelete();
        // check deleted
        $infoDeleted = $this->getCustomValueInfo($custom_value->custom_table, $custom_value->id);
        $this->assertMatch(count($infoDeleted['files']), 0); // Deleted
        $this->assertMatch(count($infoDeleted['documents']), 0); // Deleted
        $this->assertMatch(count($infoDeleted['storages']), count($info['storages']) - 3); // deleted
        $this->assertMatch(count($infoDeleted['revisions']), 0);
    }


    public function testForceDelete()
    {
        $this->init(true);
        $custom_value = $this->initTestDataDefault();

        // get custom value info
        $info = $this->getCustomValueInfo($custom_value->custom_table, $custom_value->id);
        $this->assertMatch(count($info['files']), 3);
        $this->assertMatch(count($info['documents']), 2);
        $this->assertMatch(count($info['revisions']), 2); // auto complete execute, so count is 2

        // Force delete ----------------------------------------------------
        $custom_value->delete();
        // check deleted
        $infoDeleted = $this->getCustomValueInfo($custom_value->custom_table, $custom_value->id);
        $this->assertMatch(count($infoDeleted['files']), 0); // Deleted
        $this->assertMatch(count($infoDeleted['documents']), 0); // Deleted
        $this->assertMatch(count($infoDeleted['storages']), count($info['storages']) - 3); // deleted
        $this->assertMatch(count($infoDeleted['revisions']), 0);
    }



    public function testSoftDeleteRelation()
    {
        $this->init(false);
        list($custom_value, $children) = $this->initTestDataRelation1n();

        // get custom value info
        $info = $this->getCustomValueInfo($custom_value->custom_table, $custom_value->id);
        $this->assertMatch(count($info['files']), 3);
        $this->assertMatch(count($info['documents']), 2);
        $this->assertMatch(count($info['revisions']), 1);

        // children
        $storagesCount = 0;
        foreach ($children as $child) {
            $infoChild = $this->getCustomValueInfo($child->custom_table, $child->id);
            $this->assertMatch(count($infoChild['files']), 3);
            $this->assertMatch(count($infoChild['documents']), 2);
            $this->assertMatch(count($infoChild['revisions']), 1);

            $storagesCount = count($infoChild['storages']);
        }
        $this->assertCustomRelationCount($custom_value, 10, 0);

        // Soft delete ----------------------------------------------------
        $custom_value->delete();
        // check deleted
        $infoDeleted = $this->getCustomValueInfo($custom_value->custom_table, $custom_value->id);
        $this->assertMatch(count($infoDeleted['files']), 3); // not deleted
        $this->assertMatch(count($infoDeleted['documents']), 2); // not deleted
        $this->assertMatch(count($infoDeleted['storages']), count($infoDeleted['storages'])); // not deleted
        $this->assertMatch(count($infoDeleted['revisions']), 2);

        $storagesCountChild = 0;
        foreach ($children as $child) {
            $infoChildDeleted = $this->getCustomValueInfo($child->custom_table, $child->id);
            $this->assertMatch(count($infoChildDeleted['files']), 3);
            $this->assertMatch(count($infoChildDeleted['documents']), 2);
            $this->assertMatch(count($infoChildDeleted['revisions']), 2);

            $storagesCountChild = count($infoChildDeleted['storages']);
        }
        $this->assertMatch($storagesCount, $storagesCountChild);
        $this->assertCustomRelationCount($custom_value, 0, 10);

        // Force delete ----------------------------------------------------
        $custom_value->forceDelete();
        // check deleted
        $infoDeleted = $this->getCustomValueInfo($custom_value->custom_table, $custom_value->id);
        $this->assertMatch(count($infoDeleted['files']), 0); // Deleted
        $this->assertMatch(count($infoDeleted['documents']), 0); // Deleted
        $this->assertMatch(count($infoDeleted['storages']), count($info['storages']) - 3); // deleted
        $this->assertMatch(count($infoDeleted['revisions']), 0);

        $storagesCountChild = 0;
        foreach ($children as $child) {
            $infoChildDeleted = $this->getCustomValueInfo($child->custom_table, $child->id);
            $this->assertMatch(count($infoChildDeleted['files']), 0);
            $this->assertMatch(count($infoChildDeleted['documents']), 0);
            $this->assertMatch(count($infoChildDeleted['revisions']), 0);

            $storagesCountChild = count($infoChildDeleted['storages']);
        }
        $this->assertMatch($storagesCount - 30, $storagesCountChild); // (file + document2) * 10
        $this->assertCustomRelationCount($custom_value, 0, 0);
    }


    public function testForceDeleteRelation()
    {
        $this->init(true);
        list($custom_value, $children) = $this->initTestDataRelation1n();

        // get custom value info
        $info = $this->getCustomValueInfo($custom_value->custom_table, $custom_value->id);
        $this->assertMatch(count($info['files']), 3);
        $this->assertMatch(count($info['documents']), 2);
        $this->assertMatch(count($info['revisions']), 1);

        // children
        $storagesCount = 0;
        foreach ($children as $child) {
            $infoChild = $this->getCustomValueInfo($child->custom_table, $child->id);
            $this->assertMatch(count($infoChild['files']), 3);
            $this->assertMatch(count($infoChild['documents']), 2);
            $this->assertMatch(count($infoChild['revisions']), 1);

            $storagesCount = count($infoChild['storages']);
        }
        $this->assertCustomRelationCount($custom_value, 10, 0);


        // Force delete ----------------------------------------------------
        $custom_value->delete();
        // check deleted
        $infoDeleted = $this->getCustomValueInfo($custom_value->custom_table, $custom_value->id);
        $this->assertMatch(count($infoDeleted['files']), 0); // Deleted
        $this->assertMatch(count($infoDeleted['documents']), 0); // Deleted
        $this->assertMatch(count($infoDeleted['storages']), count($info['storages']) - 3); // deleted
        $this->assertMatch(count($infoDeleted['revisions']), 0);

        $storagesCountChild = 0;
        foreach ($children as $child) {
            $infoChildDeleted = $this->getCustomValueInfo($child->custom_table, $child->id);
            $this->assertMatch(count($infoChildDeleted['files']), 0);
            $this->assertMatch(count($infoChildDeleted['documents']), 0);
            $this->assertMatch(count($infoChildDeleted['revisions']), 0);

            $storagesCountChild = count($infoChildDeleted['storages']);
        }
        $this->assertMatch($storagesCount - 30, $storagesCountChild); // (file + document2) * 10
        $this->assertCustomRelationCount($custom_value, 0, 0);
    }


    /**
     * Get custom value info.
     *     *File table uuid list for custom column
     *     *Document table id's
     *     *stroage lists
     *     *Revision id list
     *
     * @param CustomTable $custom_table
     * @param string $custom_value_id
     * @return array
     */
    protected function getCustomValueInfo(CustomTable $custom_table, $custom_value_id)
    {
        // get files
        $files = ExmentFile::where('parent_type', $custom_table->table_name)
            ->where('parent_id', $custom_value_id)
            ->select(['uuid', 'local_filename'])
            ->get()
            ->toArray();

        // get documents
        $documents = CustomTable::getEloquent(SystemTableName::DOCUMENT)
            ->getValueModel()
            ->query()
            ->where('parent_type', $custom_table->table_name)
            ->where('parent_id', $custom_value_id)
            ->select(['id', 'value'])
            ->get()
            ->toArray();

        // get storage real files
        $storages = \Storage::disk(config('admin.upload.disk'))->files($custom_table->table_name);

        // get revisions
        $revisions = Revision::where('revisionable_type', $custom_table->table_name)
            ->where('revisionable_id', $custom_value_id)
            ->select(['id', 'new_value'])
            ->get()
            ->toArray();

        return [
            'files' => $files,
            'documents' => $documents,
            'storages' => $storages,
            'revisions' => $revisions,
        ];
    }


    /**
     * assert CustomRelation Count
     */
    protected function assertCustomRelationCount(CustomValue $custom_value, int $dataCount, int $deleteCount)
    {
        // delete custom relation is 1:n value
        $relations = CustomRelation::getRelationsByParent($custom_value->custom_table, RelationType::ONE_TO_MANY);
        // loop relations
        foreach ($relations as $relation) {
            $children = $custom_value->getChildrenValues($relation, true)
                ->withTrashed()
                ->get();

            $dataCountResult = $children->filter(function ($child) {
                return !$child->trashed();
            })->count();
            $deleteCountResult = $children->filter(function ($child) {
                return $child->trashed();
            })->count();

            $this->assertMatch($dataCountResult, $dataCount);
            $this->assertMatch($deleteCountResult, $deleteCount);
        }
    }
}
