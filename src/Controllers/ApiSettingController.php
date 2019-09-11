<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Exceedone\Exment\Model\ApiClient;
use Exceedone\Exment\Enums\ApiClientType;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

class ApiSettingController extends AdminControllerBase
{
    use HasResourceActions;

    public function __construct(Request $request)
    {
        $this->setPageInfo(exmtrans("api.header"), exmtrans("api.header"), exmtrans("api.description"), 'fa-code-fork');
    }
    
    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new ApiClient);
        $grid->column('client_type_text', exmtrans('api.client_type_text'));
        $grid->column('name', exmtrans('api.app_name'));
        $grid->column('id', exmtrans('api.client_id'));
        $grid->column('created_at', trans('admin.created_at'));
        $grid->column('user_id', exmtrans('common.created_user'))->display(function ($user_id) {
            return getUserName($user_id, true);
        });
        
        $grid->disableFilter();
        $grid->disableExport();
        $grid->actions(function (Grid\Displayers\Actions $actions) {
            $actions->disableView();
        });
        return $grid;
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form($id = null)
    {
        $form = new Form(new ApiClient);
        $client = ApiClient::find($id);
        
        $form->description(exmtrans('api.help.description'));

        if (!isset($id)) {
            $form->radio('client_type', exmtrans('api.client_type_text'))->options([
                ApiClientType::CLIENT_CREDENTIALS => exmtrans('api.client_type_options.client_credentials'),
                ApiClientType::PASSWORD_GRANT => exmtrans('api.client_type_options.password_grant'),
            ])
            ->default(ApiClientType::CLIENT_CREDENTIALS)
            ->required()
            ->attribute(['data-filtertrigger' =>true])
            ->help(exmtrans('common.help.init_flg'));
        } else {
            $form->display('client_type_text', exmtrans('api.client_type_text'));
            $form->hidden('client_type');
        }
        
        $form->text('name', exmtrans('api.app_name'))->required();

        ///// toggle showing redirect
        // if create or password
        if (!isset($client) || $client->client_type == ApiClientType::CLIENT_CREDENTIALS) {
            $form->url('redirect', exmtrans('api.redirect'))
            ->required()
            ->help(exmtrans('api.help.redirect'))
            ->attribute(['data-filter' => json_encode(['key' => 'client_type', 'value' => [ApiClientType::CLIENT_CREDENTIALS]])]);
        }

        if (isset($id)) {
            $form->text('id', exmtrans('api.client_id'))->readonly();
            $form->password('secret', exmtrans('api.client_secret'))->readonly()->toggleShowEvent()
                ->help(exmtrans('api.help.client_secret'));
        }

        $form->disableReset();
        return $form;
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return mixed
     */
    public function store()
    {
        return $this->saveData();
    }

    /**
     * @param int $id
     *
     * @return \Illuminate\Http\Response
     */
    public function update($id)
    {
        return $this->saveData($id);
    }

    /**
     * create or update data
     *
     * @param string $id
     * @return Response
     */
    protected function saveData($id = null)
    {
        $request = request();

        // validation
        $validates = [
            'name' => 'required',
            'redirect' => 'url',
        ];
        if (!isset($id)) {
            $validates['client_type'] = 'required';
        }

        $validator = \Validator::make($request->all(), $validates);

        if ($validator->fails()) {
            return back()->withInput()->withErrors($validator);
        }

        $clientRepository = new ClientRepository;
        DB::beginTransaction();
        try {
            // for create token
            if (!isset($id)) {
                // create for CLIENT_CREDENTIALS
                if ($request->get('client_type') == ApiClientType::CLIENT_CREDENTIALS) {
                    $client = $clientRepository->create(
                        \Exment::user()->id,
                        $request->get('name'),
                        $request->get('redirect')
                    );
                }
                // create for password
                else {
                    $client = $clientRepository->createPasswordGrantClient(
                        \Exment::user()->id,
                        $request->get('name'),
                        'http://localhost'
                    );
                }
            }
            // update info
            else {
                $client = ApiClient::find($id);
                $client->name = $request->get('name');
                if ($client->client_type == ApiClientType::CLIENT_CREDENTIALS) {
                    $client->redirect = $request->get('redirect');
                }
                $client->save();
            }
            DB::commit();

            admin_toastr(trans('admin.update_succeeded'));
            $url = admin_urls('api_setting', $client->id, 'edit');
            return redirect($url);
        } catch (Exception $ex) {
            DB::rollback();
            throw $ex;
        }
    }
}
