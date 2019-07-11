<?php

namespace Exceedone\Exment\Controllers;

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
     * @param int $id
     *
     * @return \Illuminate\Http\Response
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
                $model = $this->form()->model()->find($id);

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
            if (!$this->form($id)->destroy($id)) {
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
            $data = [
                'status'  => false,
                'message' => trans('admin.delete_failed'),
            ];
        }

        return response()->json($data);
    }
}
