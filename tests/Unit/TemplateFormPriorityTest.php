<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomFormPriority;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\TemplateImportExport\TemplateExporter;
use Exceedone\Exment\Services\TemplateImportExport\TemplateImporter;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * Form display priorities in a template.
 *
 * An older Exment writes every form key it does not know into the form row,
 * so a "custom_form_priorities" key inside a form made its whole import fail.
 * The priorities travel in a top-level list it skips instead.
 */
class TemplateFormPriorityTest extends UnitTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    /**
     * @return void
     */
    public function testFormPrioritiesTravelOutsideTheForm()
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $formIds = CustomForm::where('custom_table_id', $table->id)->pluck('id');
        \DB::table('custom_form_priorities')->whereIn('custom_form_id', $formIds)->delete();
        $form = CustomForm::where('custom_table_id', $table->id)->firstOrFail();
        $priority = new CustomFormPriority();
        $priority->custom_form_id = $form->id;
        $priority->order = 1;
        $priority->condition_join = 'or';
        $priority->save();
        System::clearCache();

        $config = [];
        $export = new \ReflectionMethod(TemplateExporter::class, 'setTemplateTable');
        $export->setAccessible(true);
        $export->invokeArgs(null, [&$config, [$table->table_name], false]);

        $this->assertNotEmpty($config['custom_forms']);
        foreach ($config['custom_forms'] as $exported) {
            $this->assertArrayNotHasKey('custom_form_priorities', $exported, 'A form must not carry a key an older Exment cannot import.');
        }
        $this->assertSame([$form->suuid], array_column($config['custom_form_priorities'], 'suuid'));

        // The importer puts the priorities back into their form.
        \DB::table('custom_form_priorities')->where('custom_form_id', $form->id)->delete();
        (new TemplateImporter())->import([
            'custom_forms' => $config['custom_forms'],
            'custom_form_priorities' => $config['custom_form_priorities'],
        ]);
        $imported = CustomFormPriority::where('custom_form_id', $form->id)->get();
        $this->assertSame(1, $imported->count(), 'The priority must be imported into its form.');
        $this->assertSame('or', $imported->first()->condition_join);

        // A template written before this still carries them inside the form.
        \DB::table('custom_form_priorities')->where('custom_form_id', $form->id)->delete();
        $nested = array_map(function ($exported) use ($config, $form) {
            if (array_get($exported, 'suuid') === $form->suuid) {
                $exported['custom_form_priorities'] = $config['custom_form_priorities'][0]['custom_form_priorities'];
            }
            return $exported;
        }, $config['custom_forms']);
        (new TemplateImporter())->import(['custom_forms' => $nested]);
        $this->assertSame(1, CustomFormPriority::where('custom_form_id', $form->id)->count(), 'A priority inside the form must still be imported.');

        // A table without priorities leaves the template as it always was.
        \DB::table('custom_form_priorities')->whereIn('custom_form_id', $formIds)->delete();
        System::clearCache();
        $config = [];
        $export->invokeArgs(null, [&$config, [$table->table_name], false]);
        $this->assertArrayNotHasKey('custom_form_priorities', $config);
    }
}
