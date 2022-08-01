<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Services\Installer\InitializeFormTrait;
use Exceedone\Exment\Services\TemplateImportExport;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Enums\TemplateExportTarget;
use Encore\Admin\Layout\Content;
use Encore\Admin\Widgets\Box;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use GuzzleHttp\Client;

class TemplateController extends AdminControllerBase
{
    use InitializeFormTrait;

    public function __construct()
    {
        $this->setPageInfo(exmtrans("template.header"), exmtrans("template.header"), exmtrans("template.description"), 'fa-clone');
    }

    /**
     * Index interface.
     *
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        $this->AdminContent($content);
        $this->exportBox($content);
        $this->importBox($content);
        return $content;
    }

    /**
     * search template
     */
    public function searchTemplate(Request $request)
    {
        // search from exment api
        // $client = new Client();

        $q = $request->get('q');
        $name = $request->get('name');
        $column = $request->get('column');
        $page = $request->get('page') ?? 1;

        // $query = [
        //     'q' => $q,
        //     'page' => $page,
        //     'maxCount' => 10,
        // ];

        try {
            // $response = $client->request('GET', config('exment.template_search_url', 'https://exment-manage.exment.net/api/template'), [
            //     'http_errors' => false,
            //     'query' => $query,
            // ]);
            // $contents = $response->getBody()->getContents();
            // $json = json_decode_ex($contents, true);

            // // create paginator
            // $paginator = new LengthAwarePaginator(
            //     collect($json['data']),
            //     $json['total'],
            //     $json['per_page'],
            //     $json['current_page']
            // );

            // $paginator->setPath(admin_urls('template', 'search'));

            // // create datalist
            // $datalist = [];
            // foreach($json['data'] as $d){
            //     $datalist[] = [
            //         'thumbnail' => array_get($d, 'value.thumbnail'),
            //         'template_name' => array_get($d, 'value.template_name'),
            //         'description' => array_get($d, 'value.description'),
            //         'author' => array_get($d, 'value.author'),
            //         'author_url' => array_get($d, 'value.author_url'),
            //     ];
            // }

            $importer = new TemplateImportExport\TemplateImporter();
            $array = $importer->getTemplates();
            if (is_null($array)) {
                $array = [];
            }
            $no_thumbnail_file = base64_encode(file_get_contents(exment_package_path('templates/noimage.png')));

            $datalist = [];
            foreach ($array as $a) {
                // get thumbnail_path
                if (isset($a['thumbnail_file'])) {
                    $thumbnail_file = $a['thumbnail_file'];
                } else {
                    $thumbnail_file = $no_thumbnail_file;
                }

                // get delete url
                if (array_get($a, 'template_type') == 'user') {
                    $delete_url = admin_urls('webapi', 'template', 'delete?template=' . array_get($a, 'template_name'));
                } else {
                    $delete_url = null;
                }

                $datalist[] = [
                    'id' => json_encode(['template_type' => array_get($a, 'template_type'), 'template_name' => array_get($a, 'template_name')]),
                    'title' => array_get($a, 'template_view_name'),
                    'description' => array_get($a, 'description'),
                    'author' => array_get($a, 'author'),
                    'thumbnail' => 'data:image/png;base64,'.$thumbnail_file,
                    'delete_url' => $delete_url,
                ];
            }

            $paginator = new LengthAwarePaginator(
                collect($datalist),
                1,
                1,
                1
            );
            // $paginator = new LengthAwarePaginator(
            //     collect($json['data']),
            //     $json['total'],
            //     $json['per_page'],
            //     $json['current_page']
            // );

            // return body and footer
            return view('exment::form.field.tile-items', [
                'paginator' => $paginator,
                'options' => $datalist,
                'name' => $name,
                'column' => $column,
            ])->render();
        } catch (\Throwable $th) {
            \Log::error($th);
            // return body and footer
            return view('exment::form.field.tile-items', [
                'paginator' => null,
                'options' => [],
                'name' => $name,
                'column' => $column,
            ])->render();
        }
    }

    /**
     * create export box
     */
    protected function exportBox(Content $content)
    {
        $form = $this->exportBoxForm();
        $content->row((new Box(exmtrans('template.header_export'), $form))->style('info'));
    }


    /**
     * create export box
     *
     * @return \Encore\Admin\Widgets\Form
     */
    protected function exportBoxForm()
    {
        $form = new \Encore\Admin\Widgets\Form();
        $form->disablePjax();
        $form->disableReset();
        $form->action(admin_url('template/export'));

        $form->descriptionHtml(exmtrans('template.description_export'));
        $form->text('template_name', exmtrans('template.template_name'))
            ->required()
            ->help(exmtrans('common.help_code'))
            ->rules(["max:64", 'regex:/'.Define::RULES_REGEX_ALPHANUMERIC_UNDER_HYPHEN.'/']);

        $form->text('template_view_name', exmtrans('template.template_view_name'))
            ->required()
            ->rules("max:64");

        $form->textarea('description', exmtrans('template.form_description'))
            ->rows(3)
            ->rules("max:1000");

        $fileOption = Define::FILE_OPTION();
        $form->image('thumbnail', exmtrans('template.thumbnail'))
            ->removable()
            ->help(exmtrans('template.help.thumbnail'). exmtrans('common.separate_word') . array_get($fileOption, 'maxFileSizeHelp'))
            ->rules('nullable|file|mimes:jpeg,gif,png')
            ->options($fileOption);

        // export target
        $form->checkbox('export_target', exmtrans('template.export_target'))
            ->options(TemplateExportTarget::transArrayFilter('template.export_target_options', TemplateExportTarget::TEMPLATE_EXPORT_OPTIONS()))
            ->help(exmtrans('template.help.export_target'))
            ->default([TemplateExportTarget::TABLE, TemplateExportTarget::MENU])
        ;

        $form->listbox('target_tables', exmtrans('template.target_tables'))
            ->options(CustomTable::filterList()->pluck('table_view_name', 'table_name'))
            ->help(exmtrans('template.help.target_tables'))
            ->settings(['nonSelectedListLabel' => exmtrans('common.bootstrap_duallistbox_container.nonSelectedListLabel'), 'selectedListLabel' => exmtrans('common.bootstrap_duallistbox_container.selectedListLabel')]);
        ;

        $form->hidden('_token')->default(csrf_token());

        return $form;
    }

    /**
     * create import box
     */
    protected function importBox(Content $content)
    {
        $form = new \Encore\Admin\Widgets\Form();
        $form->disableReset();
        $form->action(admin_url('template/import'));

        $form->descriptionHtml(exmtrans('template.description_import'));
        $this->addTemplateTile($form);
        $form->hidden('_token')->default(csrf_token());

        $content->row((new Box(exmtrans('template.header_import'), $form))->style('info'));
    }

    /**
     * export
     */
    public function export(Request $request)
    {
        // validation
        $form = static::exportBoxForm();
        if (($response = $form->validateRedirect($request)) instanceof \Illuminate\Http\RedirectResponse) {
            return $response;
        }


        // execute export
        return TemplateImportExport\TemplateExporter::exportTemplate(
            $request->input('template_name'),
            $request->input('template_view_name'),
            $request->input('description'),
            $request->file('thumbnail'),
            [
                'export_target' => array_filter($request->input('export_target')),
                'target_tables' => array_filter($request->input('target_tables')),
            ]
        );
    }

    /**
     * import
     */
    public function import(Request $request)
    {
        \Exment::setTimeLimitLong();

        // upload template file and install
        $this->uploadTemplate($request);

        // install templates selected tiles.
        if ($request->has('template')) {
            $importer = new TemplateImportExport\TemplateImporter();
            $importer->importTemplate($request->input('template'));
        }

        admin_toastr(trans('admin.save_succeeded'));
        return back();
    }

    /**
     * delete template
     */
    public function delete(Request $request)
    {
        // install templates selected tiles.
        if ($request->has('template')) {
            $importer = new TemplateImportExport\TemplateImporter();
            $importer->deleteTemplate($request->input('template'));
        }

        return getAjaxResponse([
            'result'  => true,
            'message' => trans('admin.delete_succeeded'),
        ]);
    }
}
