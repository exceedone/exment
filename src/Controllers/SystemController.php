<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Authority;
use Exceedone\Exment\Enums\AuthorityType;
use Exceedone\Exment\Enums\SystemTableName;
use Illuminate\Support\Facades\DB;
use Encore\Admin\Widgets\Box;

class SystemController extends AdminControllerBase
{
    use InitializeForm, AuthorityForm;
    
    public function __construct(Request $request)
    {
        $this->setPageInfo(exmtrans("system.header"), exmtrans("system.header"), exmtrans("system.system_description"));
    }

    /**
     * Index interface.
     *
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        $this->AdminContent($content);
        $form = $this->getInitializeForm();
        $form->action('system');

        // Authority Setting
        $this->addAuthorityForm($form, AuthorityType::SYSTEM);

        $content->row(new Box(trans('admin.edit'), $form));
        return $content;
    }

    /**
     * Send data
     * @param Request $request
     */
    public function post(Request $request)
    {
        DB::beginTransaction();
        try {
            $result = $this->postInitializeForm($request);
            if ($result instanceof \Illuminate\Http\RedirectResponse) {
                return $result;
            }

            // Set Authority
            Authority::authorityLoop(AuthorityType::SYSTEM(), function ($authority, $related_type) use ($request) {
                $values = $request->input($authority->getAuthorityName($related_type));
                // array_filter
                $values = array_filter($values, function ($k) {
                    return isset($k);
                });
                if (!isset($values)) {
                    $values = [];
                }

                // get DB system_authoritable values
                $dbValues = DB::table(SystemTableName::SYSTEM_AUTHORITABLE)
                    ->where('related_type', $related_type)
                    ->where('morph_type', AuthorityType::SYSTEM())
                    ->where('authority_id', $authority->id)
                    ->get(['related_id']);
                foreach ($values as $value) {
                    if (!isset($value)) {
                        continue;
                    }
                    /// not exists db value, insert
                    if (!$dbValues->first(function ($dbValue, $k) use ($value) {
                        return $dbValue->related_id == $value;
                    })) {
                        DB::table(SystemTableName::SYSTEM_AUTHORITABLE)->insert(
                        [
                            'related_id' => $value,
                            'related_type' => $related_type,
                            'morph_id' => null,
                            'morph_type' => AuthorityType::SYSTEM(),
                            'authority_id' => $authority->id,
                        ]
                    );
                    }
                }

                ///// Delete if not exists value
                foreach ($dbValues as $dbValue) {
                    if (!collect($values)->first(function ($value, $k) use ($dbValue) {
                        return $dbValue->related_id == $value;
                    })) {
                        DB::table(SystemTableName::SYSTEM_AUTHORITABLE)
                        ->where('related_id', $dbValue->related_id)
                        ->where('related_type', $related_type)
                        ->where('morph_type', AuthorityType::SYSTEM())
                        ->where('authority_id', $authority->id)
                        ->delete();
                    }
                }
            });

            DB::commit();

            admin_toastr(trans('admin.save_succeeded'));

            return redirect(admin_base_path('system'));
        } catch (Exception $exception) {
            //TODO:error handling
            DB::rollback();
            throw $exception;
        }
    }
}
