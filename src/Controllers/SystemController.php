<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Authority;
use Illuminate\Support\Facades\DB;
use Encore\Admin\Widgets\Box;

class SystemController extends AdminControllerBase
{
    use InitializeForm, AuthorityForm;
    
    public function __construct(Request $request){
        $this->setPageInfo(exmtrans("system.header"), exmtrans("system.header"), exmtrans("system.system_description"));
    }

    /**
     * Index interface.
     *
     * @return Content
     */
    public function index(Request $request)
    {
        return $this->AdminContent(function (Content $content) {
            $form = $this->getInitializeForm();
            $form->action('system');

            // Authority Setting
            $this->addAuthorityForm($form, Define::AUTHORITY_TYPE_SYSTEM);

            $content->row(new Box(trans('admin.edit'), $form));
        });
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
            authorityLoop(Define::AUTHORITY_TYPE_SYSTEM, function ($authority, $related_type) use ($request) {
                $values = $request->input(getAuthorityName($authority, $related_type));

                // get DB system_authoritable values
                $dbValues = DB::table('system_authoritable')
                    ->where('related_type', $related_type)
                    ->where('morph_type', Define::AUTHORITY_TYPE_SYSTEM)
                    ->where('authority_id', $authority->id)
                    ->get(['related_id']);
                if (isset($values)) {
                    foreach ($values as $value) {
                        if (!isset($value)) {
                            continue;
                        }
                        /// not exists db value, insert
                        if (!$dbValues->first(function ($dbValue, $k) use ($value) {
                            return $dbValue->related_id == $value;
                        })) {
                            DB::table('system_authoritable')->insert(
                            [
                                'related_id' => $value,
                                'related_type' => $related_type,
                                'morph_id' => null,
                                'morph_type' => Define::AUTHORITY_TYPE_SYSTEM,
                                'authority_id' => $authority->id,
                            ]
                        );
                        }
                    }
                }

                ///// Delete if not exists value
                foreach($dbValues as $dbValue){
                    if (is_null($values) && !collect($values)->first(function ($value, $k) use($dbValue) {
                        return $dbValue->related_id == $value;
                    })) {
                        DB::table('system_authoritable')
                        ->where('related_id', $dbValue->related_id)
                        ->where('related_type', $related_type)
                        ->where('morph_type', Define::AUTHORITY_TYPE_SYSTEM)
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
