<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Exceedone\Exment\Model\ApiClient;
use Exceedone\Exment\Model\ApiClientRepository;
use Exceedone\Exment\Auth\Permission as Checker;
use Exceedone\Exment\Enums\ApiClientType;
use Exceedone\Exment\Form\Tools;

class ApiSettingController extends AdminControllerBase
{
    use HasResourceActions;

    public function __construct()
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
        $grid = new Grid(new ApiClient());
        $grid->column('client_type_text', exmtrans('api.client_type_text'));
        $grid->column('name', exmtrans('api.app_name'));
        $grid->column('id', exmtrans('api.client_id'));
        $grid->column('created_at', trans('admin.created_at'));
        $grid->column('user_id', exmtrans('common.created_user'))->display(function ($user_id) {
            return getUserName($user_id, true);
        })->escape(false);

        $grid->filter(function ($filter) {
            $filter->disableIdFilter();

            $filter->exmwhere(function ($query, $input) {
                switch ($input) {
                    case ApiClientType::API_KEY:
                        $query->where('api_key_client', 1);
                        return;
                    case ApiClientType::PASSWORD_GRANT:
                        $query->where('password_client', 1);
                        return;
                    case ApiClientType::CLIENT_CREDENTIALS:
                        $query->where('personal_access_client', 0)->where('password_client', 0)->where('api_key_client', 0);
                        return;
                }
            }, exmtrans("api.client_type_text"))->select(ApiClientType::transArray('api.client_type_options'));

            $filter->like('name', exmtrans("api.app_name"));
            $filter->like('id', exmtrans("api.client_id"));

            //$filter->betweendatetime('created_at', trans('admin.created_at'))->date();
        });

        $grid->disableExport();
        $grid->actions(function (Grid\Displayers\Actions $actions) {
            $actions->disableView();
        });
        $grid->tools(function (Grid\Tools $tools) {
            $tools->prepend(new Tools\SystemChangePageMenu());
        });
        return $grid;
    }

    /**
     * Make a form builder.
     *
     * @param $id
     * @return Form|false
     */
    protected function form($id = null)
    {
        $form = new Form(new ApiClient());
        $client = ApiClient::find($id);
        if (isset($id) && !isset($client)) {
            Checker::notFoundOrDeny();
            return false;
        }

        $form->descriptionHtml(exmtrans('common.help.more_help'));

        if (!isset($id)) {
            $form->radio('client_type', exmtrans('api.client_type_text'))->options(ApiClientType::transArray('api.client_type_options'))
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
            $form->text('id', exmtrans('api.client_id'))->attribute(['copyScript' => 1])->readonly();
            $form->password('secret', exmtrans('api.client_secret'))->readonly()->toggleShowEvent()
                ->attribute(['copyScript' => 1])
                ->help(exmtrans('api.help.client_secret'));

            if ($client->client_type == ApiClientType::API_KEY) {
                $client_api_key = $client->client_api_key;

                $form->password('client_api_key.key', exmtrans('api.api_key'))->readonly()->toggleShowEvent()
                ->attribute(['copyScript' => 1])
                    ->help(exmtrans('api.help.api_key') . exmtrans('api.help.client_secret'));

                $form->display('user_id', exmtrans('common.executed_user'))->displayText(function ($user_id) {
                    return getUserName($user_id, true);
                })->help(exmtrans('api.help.executed_user'))->escape(false);
            }
        }

        $form->tools(function (Form\Tools $tools) {
            $tools->append(new Tools\SystemChangePageMenu());
        });

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
     * @param $id
     * @return Response
     * @throws \Exception
     */
    public function update($id)
    {
        return $this->saveData($id);
    }

    /**
     * create or update data
     *
     * @param $id
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector|true
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \Throwable
     */
    protected function saveData($id = null)
    {
        $request = request();

        // validation
        $form = $this->form($id);
        if (($response = $form->validateRedirect($request->all())) instanceof \Illuminate\Http\RedirectResponse) {
            return $response;
        }

        $clientRepository = new ApiClientRepository();
        DB::beginTransaction();
        try {
            $client = null;
            // for create token
            if (!isset($id)) {
                $user_id = \Exment::getUserId();
                $name = $request->get('name');
                $client_type = $request->get('client_type');

                // create for CLIENT_CREDENTIALS
                if ($client_type == ApiClientType::CLIENT_CREDENTIALS) {
                    $client = $clientRepository->create(
                        $user_id,
                        $name,
                        $request->get('redirect')
                    );
                }
                // create for password
                elseif ($client_type == ApiClientType::PASSWORD_GRANT) {
                    $client = $clientRepository->createPasswordGrantClient(
                        $user_id,
                        $name,
                        'http://localhost'
                    );
                } elseif ($client_type == ApiClientType::API_KEY) {
                    $client = $clientRepository->createApiKey(
                        $user_id,
                        $name,
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
        } catch (\Exception $ex) {
            DB::rollback();
            throw $ex;
        }
    }
}
