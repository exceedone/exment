<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Http\JsonResponse;

class ChatbotController extends BaseController
{
    /**
     * Returns chatbot configuration including UI texts and idle time.
     *
     * @return JsonResponse
     */
    public function config(): JsonResponse
    {
        $timeidle = System::chatbot_available();
        if (!$timeidle) {
            return response()->json([]);
        }
        $uiTextTable = CustomTable::getEloquent(SystemTableName::CHATBOT_UI_TEXT);
        $uiTexts = $uiTextTable ? $uiTextTable->getValueModel()->all() : [];
        $uiTexts = collect($uiTexts)->map(function ($item) {
            $value = is_array($item->value) ? $item->value : (array) $item->value;
            return array_merge(['id' => $item->id], $value);
        })->values();
        return response()->json([
            'ui_texts' => $uiTexts,
            'timeidle' => $timeidle,
        ]);
    }
    /**
     * Returns a list of featured FAQ entries filtered by workflow status.
     *
     * @return JsonResponse
     */
    public function faq(): JsonResponse
    {
        $customTable = CustomTable::getEloquent(SystemTableName::CHATBOT_FAQ);
        $records = $customTable->getValueModel()->query()->where('value->is_featured', 1)->orderBy('value->display_order')->get();

        if (System::chatbot_faq_wf()) {
            $filterArray = preg_split('/\r\n|\r|\n/', System::chatbot_faq_wf_status_filters());
            $filterArray = array_filter(array_map('trim', $filterArray));

            $filter = $records->filter(function ($record) use ($filterArray) {
                return in_array($record->workflow_status_name, $filterArray);
            });
        } else {
            $filter = $records;
        }
        $result = $filter->map(function ($record) {
            return [
                'question' => $record->value['question'] ?? null,
                'display_order' => $record->value['display_order'] ?? null,
            ];
        })->values()->all();

        return response()->json($result, 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
