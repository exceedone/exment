<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Model\MeiliDictionary;
use ExmentAdminCore\Admin\Grid;
use ExmentAdminCore\Admin\Form;
use ExmentAdminCore\Admin\Layout\Content;
use Illuminate\Http\Request;

/**
 * Admin screen for the Meilisearch relevance dictionary (synonyms + stop words).
 *
 * These are index settings, so saving/deleting applies via a light job
 * (ApplyMeiliSettingsJob) WITHOUT reindexing documents.
 */
class MeiliDictionaryController extends AdminControllerBase
{
    use HasResourceActions;

    public function __construct()
    {
        $this->setPageInfo(
            exmtrans('system.meili_dictionary'),
            exmtrans('system.meili_dictionary'),
            exmtrans('system.help.meili_dictionary'),
            'fa-language'
        );
    }

    public function index(Request $request, Content $content)
    {
        $this->AdminContent($content);
        $content->body($this->grid());
        return $content;
    }

    public function create(Request $request, Content $content)
    {
        $this->AdminContent($content);
        $content->body($this->form());
        return $content;
    }

    public function edit(Request $request, Content $content, $id)
    {
        $this->AdminContent($content);
        $content->body($this->form()->edit($id));
        return $content;
    }

    /**
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new MeiliDictionary());
        $grid->column('type', exmtrans('system.meili_dictionary_type'));
        $grid->column('word', exmtrans('system.meili_dictionary_word'));
        $grid->column('synonyms', exmtrans('system.meili_dictionary_synonyms'));
        $grid->column('order', exmtrans('custom_table.order'))->sortable();
        $grid->column('enabled', exmtrans('system.meili_enabled'))->bool();

        $grid->disableExport();
        $grid->disableColumnSelector();
        return $grid;
    }

    /**
     * $id is passed by HasResourceActions::update().
     *
     * @param int|string|null $id
     * @return Form
     */
    protected function form($id = null)
    {
        $form = new Form(new MeiliDictionary());

        $form->select('type', exmtrans('system.meili_dictionary_type'))
            ->required()
            ->options([
                MeiliDictionary::TYPE_SYNONYM => exmtrans('system.meili_dictionary_type_synonym'),
                MeiliDictionary::TYPE_STOPWORD => exmtrans('system.meili_dictionary_type_stopword'),
            ])
            ->default(MeiliDictionary::TYPE_SYNONYM)
            ->help(exmtrans('system.help.meili_dictionary_type'));

        $form->text('word', exmtrans('system.meili_dictionary_word'))
            ->required()
            ->help(exmtrans('system.help.meili_dictionary_word'));

        $form->textarea('synonyms', exmtrans('system.meili_dictionary_synonyms'))
            ->rows(3)
            ->help(exmtrans('system.help.meili_dictionary_synonyms'));

        $form->number('order', exmtrans('custom_table.order'))->default(0);
        $form->switchbool('enabled', exmtrans('system.meili_enabled'))->default(1);

        return $form;
    }
}
