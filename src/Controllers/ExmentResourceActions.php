<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Controllers\HasResourceActions;

trait ExmentResourceActions
{
    use HasResourceActions {
        HasResourceActions::destroy as parentDestroy;
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
        return $this->parentDestroy($id);
    }
}
