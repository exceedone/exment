<?php

namespace Exceedone\Exment\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Admin(Exment) Controller
 *
* @method \Encore\Admin\Grid grid()
* @method \Encore\Admin\Form form($id = null)
 */
trait HasResourceTableActions
{
    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     *
     * @return \Illuminate\Http\Response
     */
    public function update($tableKey, $id)
    {
        return $this->form($id)->update($id);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return mixed
     */
    public function store()
    {
        return $this->form()->store();
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param $tableKey
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($tableKey, $id)
    {
        if (method_exists($this, 'validateDestroy')) {
            $data = $this->validateDestroy($id);
            if (!empty($data)) {
                return response()->json($data);
            }
        }

        $rows = collect(explode(',', $id))->filter();

        // check row's disabled_delete
        $disabled_delete = false;
        $rows->each(function ($id) use (&$disabled_delete) {
            if (!$disabled_delete) {
                $model = $this->form($id)->model()->find($id);

                if (boolval(array_get($model, 'disabled_delete'))) {
                    $disabled_delete = true;
                }
            }
        });

        if ($disabled_delete) {
            return response()->json([
                'status'  => false,
                'message' => exmtrans('error.disable_delete_row'),
                'reload' => false,
            ]);
        }

        $result = true;
        $messages = [];
        $rows->each(function ($id) use (&$result, &$messages) {
            $res = $this->form($id)->destroy($id);
            if ($res instanceof JsonResponse) {
                $data = $res->getData();
                if ($data->status === false) {
                    $result = false;
                    $messages[] = $data->message;
                    return;
                }
            } elseif (!$res) {
                $result = false;
                return;
            }
        });

        if ($result) {
            $data = [
                'status'  => true,
                'message' => trans('admin.delete_succeeded'),
            ];
        } else {
            if (count($messages) == 1) {
                $data = [
                    'status'  => false,
                    'message' => $messages[0],
                ];
            } else {
                $data = [
                    'status'  => false,
                    'message' => trans('admin.delete_failed'),
                ];
            }
            if ($rows->count() !== count($messages)) {
                $data['forceRedirect'] = true;
            }
        }

        return response()->json($data);
    }
}
