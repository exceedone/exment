<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Services\TemplateImportExport;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Enums\TemplateExportTarget;
use Encore\Admin\Layout\Content;
use Encore\Admin\Widgets\Box;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use GuzzleHttp\Client;
use Validator;

class TemplateController extends AdminControllerBase
{
    use InitializeForm;
        
    public function __construct(Request $request)
    {
        $this->setPageInfo(exmtrans("template.header"), exmtrans("template.header"), exmtrans("template.description"));
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
            // $json = json_decode($contents, true);
    
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
    
            $array = TemplateImportExport\TemplateImporter::getTemplates();
            if (is_null($array)) {
                $array = [];
            }
            $datalist = [];
            foreach ($array as $a) {
                // get thumbnail_path
                if (isset($a['thumbnail_fullpath'])) {
                    $thumbnail_path = $a['thumbnail_fullpath'];
                } else {
                    $thumbnail_path = base_path() . '/vendor/exceedone/exment/templates/noimage.png';
                }
                array_push($datalist, [
                    'id' => array_get($a, 'template_name'),
                    'title' => array_get($a, 'template_view_name'),
                    'description' => array_get($a, 'description'),
                    'author' => array_get($a, 'author'),
                    'thumbnail' => 'data:image/png;base64,'.base64_encode(file_get_contents($thumbnail_path))
                ]);
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
                'datalist' => $datalist,
                'name' => $name,
                'column' => $column,
            ])->render();
        } catch (\Throwable $th) {
            // return body and footer
            return view('exment::form.field.tile-items', [
                'paginator' => null,
                'datalist' => [],
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
        $form = new \Encore\Admin\Widgets\Form();
        $form->disablePjax();
        $form->disableReset();
        $form->action(admin_url('template/export'));

        $form->description(exmtrans('template.description_export'));
        $form->text('template_name', exmtrans('template.template_name'))->required()->help(exmtrans('common.help_code'));
        $form->text('template_view_name', exmtrans('template.template_view_name'))->required();
        $form->textarea('description', exmtrans('template.form_description'))->rows(3);
        $form->image('thumbnail', exmtrans('template.thumbnail'))->help(exmtrans('template.help.thumbnail'))->options(Define::FILE_OPTION());

        // export target
        $form->checkbox('export_target', exmtrans('template.export_target'))
            ->options(TemplateExportTarget::transArray('template.export_target_options'))
            ->help(exmtrans('template.help.export_target'))
            ->default([TemplateExportTarget::TABLE, TemplateExportTarget::DASHBOARD])
            ;
        
        $form->listbox('target_tables', exmtrans('template.target_tables'))
            ->options(CustomTable::filterList()->pluck('table_view_name', 'table_name'))
            ->help(exmtrans('template.help.target_tables'))
            ->settings(['nonSelectedListLabel' => exmtrans('custom_value.bootstrap_duallistbox_container.nonSelectedListLabel'), 'selectedListLabel' => exmtrans('custom_value.bootstrap_duallistbox_container.selectedListLabel')]);
        ;

        $form->hidden('_token')->default(csrf_token());

        $content->row((new Box(exmtrans('template.header_export'), $form))->style('info'));
    }
    /**
     * create import box
     */
    protected function importBox(Content $content)
    {
        $form = new \Encore\Admin\Widgets\Form();
        $form->disableReset();
        $form->action(admin_url('template/import'));

        $form->description(exmtrans('template.description_import'));
        $this->addTemplateTile($form);
        $form->hidden('_token')->default(csrf_token());

        $content->row((new Box(exmtrans('template.header_import'), $form))->style('info'));
    }

    /**
     * export
     */
    public function export(Request $request)
    {
        //validate
        $rules = [
            'template_name' => 'required|max:64|regex:/'.Define::RULES_REGEX_ALPHANUMERIC_UNDER_HYPHEN.'/',
            'template_view_name' => 'required|max:64',
            'thumbnail' => 'nullable|file|mimes:jpeg,gif,png',
            'export_target' => 'required',
        ];

        $validation = Validator::make($request->all(), $rules);

        if ($validation->fails()) {
            return back()->withInput()->withErrors($validation);
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
        // upload template file and install
        $this->uploadTemplate($request);

        // install templates selected tiles.
        if ($request->has('template')) {
            TemplateImportExport\TemplateImporter::importTemplate($request->input('template'));
        }

        admin_toastr(trans('admin.save_succeeded'));
        return back();
    }
}
