<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Controllers\HasResourceActions as ParentResourceActions;
use Symfony\Component\HttpFoundation\Response;

trait HasResourceActions
{
    use ParentResourceActions;

    protected $isDeleteForce = false;

    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     *
     * @return \Illuminate\Http\Response
     */
    public function update($id)
    {
        return $this->form($id)->update($id);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
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
                if (method_exists($this, 'getModel')) {
                    $model = $this->getModel($id);
                } else {
                    $model = $this->form($id)->setIsForceDelete($this->isDeleteForce)->model()->find($id);
                }

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
        $rows->each(function ($id) use (&$result) {
            if (method_exists($this, 'widgetDestroy')) {
                if (!$this->widgetDestroy($id)) {
                    $result = false;
                    return;
                }
            } else {
                /** @var \Illuminate\Http\Response|bool $response */
                $response = $this->form($id)->setIsForceDelete($this->isDeleteForce)->destroy($id);
                if ($response === false) {
                    $result = false;
                    return;
                }

                // if response instanceof Response, and status is false, result is false
                elseif ($response instanceof Response) {
                    $content = jsonToArray($response->content());
                    if (is_array($content) && !boolval(array_get($content, 'status', true))) {
                        $result = false;
                        return;
                    }
                }
            }
        });

        if ($result) {
            $data = [
                'status'  => true,
                'message' => trans('admin.delete_succeeded'),
            ];
        } else {
            $data = [
                'status'  => false,
                'message' => exmtrans('error.delete_failed'),
            ];
        }

        return response()->json($data);
    }
}
