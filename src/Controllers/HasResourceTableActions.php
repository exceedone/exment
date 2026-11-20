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
    // @phpstan-ignore-next-line
    public function update($tableKey, $id)
    {
        if (!$this->validateResourceRows($id)) {
            return;
        }
        return $this->form($id)->update($id);
    }

    /**
     * A controller of a table's setting names the model of its rows and the
     * permission they need (resourceRowRule()); the rows are then checked to be
     * of this table before update() and destroy().
     *
     * @param mixed $id
     * @return bool
     */
    protected function validateResourceRows($id): bool
    {
        if (!method_exists($this, 'resourceRowRule')) {
            return true;
        }
        $rule = $this->resourceRowRule();
        return $this->validateRowsOfTable($rule[0], $id, $rule[1], $rule[2] ?? null);
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
    // @phpstan-ignore-next-line
    public function destroy($tableKey, $id)
    {
        if (!$this->validateResourceRows($id)) {
            return response()->json([
                'status'  => false,
                'message' => trans('admin.deny'),
                'reload' => false,
            ]);
        }

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

                /** @phpstan-ignore-next-line */
                if (boolval(array_get($model, 'disabled_delete'))) {
                    $disabled_delete = true;
                }
            }
        });

        if ($disabled_delete) {
            return response()->json([
                'status'  => false,
                /** @phpstan-ignore-next-line */
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
                    /** @phpstan-ignore-next-line */
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
                /** @phpstan-ignore-next-line */
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
                    /** @phpstan-ignore-next-line */
                    'message' => trans('admin.delete_failed'),
                ];
            }
            /** @phpstan-ignore-next-line */
            if ($rows->count() !== count($messages)) {
                $data['forceRedirect'] = true;
            }
        }

        return response()->json($data);
    }
}
