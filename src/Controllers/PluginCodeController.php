<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Layout\Content;
use Encore\Admin\Auth\Permission as Checker;
use Encore\Admin\Layout\Row;
use Encore\Admin\Widgets\Box;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Enums\Permission;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Validator;

class PluginCodeController extends AdminControllerBase
{
    use CodeTreeTrait;

    protected $plugin;

    protected const node_key = Define::SYSTEM_KEY_SESSION_FILE_NODELIST;

    /**
     * constructer
     *
     */
    public function __construct()
    {
        $this->setPageInfo(exmtrans("plugincode.header"), exmtrans("plugincode.header"), exmtrans("plugincode.description"), 'fa-plug');
    }

    /**
     * Showing code edit page
     *
     * @param Request $request
     * @param Content $content
     * @param $id
     * @return Content|false
     */
    public function edit(Request $request, Content $content, $id)
    {
        $this->AdminContent($content);
        session()->forget(static::node_key);

        $this->plugin = Plugin::getEloquent($id);

        if (!$this->plugin->hasPermission(Permission::PLUGIN_SETTING)) {
            Checker::error();
            return false;
        }
        $content->row(function (Row $row) use ($request, $id) {
            // get nodeid
            $json = $this->getTreeDataJson($request);
            $node = collect($json)->first(function ($j) {
                return isMatchString(array_get($j, 'path'), '/');
            });

            $row->column(9, view('exment::plugin.editor.upload', [
                'url' => admin_url("plugin/edit_code/$id/fileupload"),
                'filepath' => '/',
                'message' => exmtrans('plugincode.message.upload_file'),
                'nodeid' => array_get($node, 'id'),
            ]));

            $row->column(3, $this->getJsTreeBox($id));
        });

        return $content;
    }

    protected function getJsTreeBox($id)
    {
        $view = view('exment::widgets.jstree', [
            'data_get_url' => "$id/getTree",
            'file_get_url' => "$id/selectFile",
        ]);
        $box = new Box('', $view);
        $box->tools(view('exment::plugin.editor.buttons', [
            'id' => $id,
        ]))->style('info');

        return $box->render();
    }

    /**
     * Get file tree data
     *
     * @param Request $request
     * @param $id
     * @return false|\Illuminate\Http\JsonResponse
     */
    public function getTreeData(Request $request, $id)
    {
        $this->plugin = Plugin::getEloquent($id);
        if (!$this->plugin->hasPermission(Permission::PLUGIN_SETTING)) {
            Checker::error();
            return false;
        }

        return response()->json($this->getTreeDataJson($request));
    }

    /**
     * Get file tree data
     *
     * @param Request $request
     * @return array|\Illuminate\Contracts\Foundation\Application|\Illuminate\Session\SessionManager|\Illuminate\Session\Store|mixed
     */
    protected function getTreeDataJson(Request $request)
    {
        if (session()->has(static::node_key)) {
            return session(static::node_key);
        }

        $json = [];
        $this->setDirectoryNodes('/', '#', $json, true);

        // set session
        session([static::node_key => $json]);
        return $json;
    }

    /**
     * Upload file to target folder
     *
     * @param Request $request
     * @param $id
     * @return false|\Illuminate\Http\RedirectResponse
     * @throws FileNotFoundException
     */
    public function fileupload(Request $request, $id)
    {
        $this->plugin = Plugin::getEloquent($id);

        if (!$this->plugin->hasPermission(Permission::PLUGIN_SETTING)) {
            Checker::error();
            return false;
        }

        $validator = \Validator::make($request->all(), [
            'nodeid' => 'required',
        ]);
        if ($validator->fails()) {
            return back()->with('errorMess', exmtrans("plugincode.error.folder_notfound"));
        }

        if ($request->hasfile('fileUpload')) {
            $nodeid = $request->get('nodeid');
            $folder_path = $this->getNodePath($nodeid);

            $upload_files = $request->file('fileUpload');

            foreach ($upload_files as $upload_file) {
                $filename = $upload_file->getClientOriginalName();

                $this->plugin->putAsPluginFile($folder_path, $filename, $upload_file);
            }

            $this->updatePluginDatetime();
            admin_toastr(exmtrans('common.message.success_execute'));
            return back();
        }
        // if not exists, return back and message
        return back()->with('errorMess', exmtrans("plugin.help.errorMess"));
    }

    /**
     * Get child form html for selected file
     *
     * @param Request $request
     * @param $id
     * @return array
     * @throws \Exception
     */
    public function getFileEditForm(Request $request, $id)
    {
        $this->plugin = Plugin::getEloquent($id);

        list($view, $isBox) = $this->getFileEditFormView($request, $id);

        if ($isBox) {
            $box = new Box('', $view);
            $view = $box->style('info');
        }
        return [
            'editor' => $view->render()
        ];
    }

    /**
     * Get child form html for selected file
     *
     * @param Request $request
     * @param $id
     * @return array|void
     * @throws \Exception
     */
    protected function getFileEditFormView(Request $request, $id)
    {
        $validator = \Validator::make($request->all(), [
            'nodeid' => 'required',
        ]);
        if ($validator->fails()) {
            return [view('exment::plugin.editor.info'), false];
        }

        $nodeid = $request->get('nodeid');
        $nodepath = $this->getNodePath($nodeid);

        try {
            if ($this->plugin->isPathDir($nodepath)) {
                return [view('exment::plugin.editor.upload', [
                    'url' => admin_url("plugin/edit_code/$id/fileupload"),
                    'filepath' => $nodepath,
                    'message' => exmtrans('plugincode.message.upload_file'),
                    'nodeid' => $nodeid,
                ]), false];
            }

            list($mode, $image, $can_delete) = $this->getPluginFileType($nodepath);

            $message = exmtrans('plugincode.message.irregular_ext');

            if (isset($image)) {
                $filedata = $this->plugin->getPluginFiledata($nodepath);
                return [view('exment::plugin.editor.image', [
                    'image' => base64_encode($filedata),
                    'url' => admin_url("plugin/edit_code/$id"),
                    'ext' => $image,
                    'filepath' => $nodepath,
                    'nodeid' => $nodeid,
                    'can_delete' => $can_delete,
                ]), true];
            } elseif ($mode !== false) {
                $filedata = $this->plugin->getPluginFiledata($nodepath);
                $enc = mb_detect_encoding($filedata, ['UTF-8', 'UTF-16', 'ASCII', 'ISO-2022-JP', 'EUC-JP', 'SJIS'], true);
                if ($enc == 'UTF-8') {
                    return [view('exment::plugin.editor.code', [
                        'url' => admin_url("plugin/edit_code/$id"),
                        'filepath' => $nodepath,
                        'nodeid' => $nodeid,
                        'filedata' => $filedata,
                        'mode' => $mode,
                    ]), true];
                } else {
                    $message = exmtrans('plugincode.message.irregular_enc');
                }
            }

            return [view('exment::plugin.editor.other', [
                'url' => admin_url("plugin/edit_code/$id"),
                'filepath' => $nodepath,
                'nodeid' => $nodeid,
                'can_delete' => $can_delete,
                'message' => $message
            ]), false];
        } catch (FileNotFoundException $ex) {
            //Todo:FileNotFoundException
        }
    }

    /**
     * Get CodeMirror mode and image type of file
     *
     * @param string $nodepath
     * @return array [CodeMirror mode, image extension, deletable flg]
     */
    protected function getPluginFileType($nodepath)
    {
        // exclude config.json
        if (mb_strtolower(basename($nodepath)) === 'config.json') {
            return [false, null, false];
        }

        // check extension
        $ext = \File::extension($nodepath);
        $mode = false;
        $image = null;

        switch ($ext) {
            case 'php':
            case 'css':
                $mode = $ext;
                break;
            case 'js':
                $mode = 'javascript';
                break;
            case 'json':
                $mode = "{ name: 'javascript', json: true}";
                break;
            case 'txt':
                $mode = null;
                break;
            case 'jpg':
            case 'jpeg':
                $image = 'jpeg';
                break;
            case 'gif':
                $image = 'gif';
                break;
            case 'png':
                $image = 'png';
                break;
        }
        return [$mode, $image, true];
    }

    /**
     * delete target file from plugin folder
     *
     * @param Request $request
     * @param $id
     * @return false|Response
     * @throws FileNotFoundException
     */
    public function delete(Request $request, $id)
    {
        $this->plugin = Plugin::getEloquent($id);
        if (!$this->plugin->hasPermission(Permission::PLUGIN_SETTING)) {
            Checker::error();
            return false;
        }

        $validator = Validator::make($request->all(), [
            'nodeid' => 'required',
        ]);

        if ($validator->fails()) {
            return getAjaxResponse([
                'result'  => false,
                'toastr' => $validator->errors()->first(),
                'reload' => false,
            ]);
        }

        $nodeid = $request->get('nodeid');
        $nodepath = $this->getNodePath($nodeid);

        $this->plugin->deletePluginFile($nodepath);

        $this->updatePluginDatetime();

        return getAjaxResponse([
            'result'  => true,
            'toastr' => trans('admin.delete_succeeded'),
            'reload' => false,
        ]);
    }

    /**
     * update file in plugin folder
     *
     * @param Request $request
     * @param $id
     * @return false|Response
     * @throws FileNotFoundException
     */
    public function store(Request $request, $id)
    {
        $this->plugin = Plugin::getEloquent($id);
        if (!$this->plugin->hasPermission(Permission::PLUGIN_SETTING)) {
            Checker::error();
            return false;
        }

        $validator = Validator::make($request->all(), [
            'nodeid' => 'required',
            'edit_file' => 'required',
        ]);

        if ($validator->fails()) {
            return getAjaxResponse([
                'result'  => false,
                'toastr' => $validator->errors()->first(),
                'reload' => false,
            ]);
        }

        $nodeid = $request->get('nodeid');
        $nodepath = $this->getNodePath($nodeid);
        $edit_file = $request->get('edit_file');

        $this->plugin->putPluginFile($nodepath, $edit_file);

        $this->updatePluginDatetime();

        return getAjaxResponse([
            'result'  => true,
            'toastr' => trans('admin.save_succeeded'),
            'reload' => false,
        ]);
    }


    /**
     * Update plugin's updated_at. Because sync files from crowd.
     *
     * @return void
     */
    protected function updatePluginDatetime()
    {
        $this->plugin->update([
            'updated_at' => \Carbon\Carbon::now(),
        ]);
    }


    protected function getDirectoryPaths($folder)
    {
        return $this->plugin->getPluginDirPaths($folder, false);
    }


    protected function getFilePaths($folder)
    {
        return $this->plugin->getPluginFilePaths($folder, false);
    }
}
