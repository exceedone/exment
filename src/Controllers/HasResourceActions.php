<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Controllers\HasResourceActions as ParentResourceActions;

trait HasResourceActions
{
    use ParentResourceActions;
    
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
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        if (method_exists($this, 'validateDestroy')) {
            $data = $this->validateDestroy($id);
            if (!empty($data)) {
                return response()->json($data);
            }
        }
        if ($this->form($id)->destroy($id)) {
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
