<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Exceedone\Exment\Model\AssistantTable;
use Illuminate\Support\Facades\Http;
use Illuminate\Database\Eloquent\ModelNotFoundException;

use Encore\Admin\Layout\Content;

class AiAssistantController extends AdminControllerBase
{
    protected string $aiAssistantServerUrl = 'https://exment.org/api/ai_assistant/assistant-tables/';
    protected string $bearerToken  = '1|alBBL8vpczVdvUGB44TxoHL0NSV97BrDYV3LBig3fb5e70d2';

    public function aiAssistant(Content $content)
    {
        // Check permission. if not permission, show message
        if (\Exment::user()->noPermission()) {
            admin_warning(trans('admin.deny'), exmtrans('common.help.no_permission'));
        }

        // Set a title for the page
        $content->headericon('fa-robot');
        $content->header(exmtrans('ai_assistant.header'));
        $content->description(exmtrans('ai_assistant.description'));

        // Add main content to admin layout
        $content->row(function ($row) {
            // The chat box will be placed in the middle column of the page.
            $row->column(12, function ($column) {
                $column->append(view('exment::ai-assistant.assistant'));
            });
        });

        // Returns the Content object, Laravel Admin will render it with full layout
        return $content;
    }

    public function startConversation(Request $request)
    {
        $validated = $request->validate([
            'feature_type' => 'required|in:custom_table,workflow,schedule_notifications',
        ]);

        $model = null;
        $welcomeMessage = '';

        switch ($validated['feature_type']) {
            case 'custom_table':
                $model = AssistantTable::create(['status' => 'init']);
                $welcomeMessage = exmtrans('ai_assistant.welcome_message');
                break;
            case 'workflow':
            case 'schedule':
                break;
        }

        if (!$model) {
            return response()->json(['message' => 'Failed to start conversation.'], 500);
        }

        $model->messages()->create([
            'message_text' => $welcomeMessage,
            'role' => 'assistant',
        ]);

        return response()->json([
            'message' => $welcomeMessage,
            'uuid' => $model->id,
            'showActionButtons' => false,
        ]);
    }

    public function sendMessage(Request $request)
    {
        $validated = $request->validate([
            'uuid' => 'required|uuid',
            'message' => 'required|string',
        ]);

        try {
            $conversable = $this->findConversable($validated['uuid']);
            if (!$conversable) {
                throw new ModelNotFoundException();
            }

            $conversable->messages()->create([
                'message_text' => $validated['message'],
                'role' => 'user',
            ]);

            $endpoints = [
                'init' => 'store',
                'confirming' => 'edit',
            ];
            $ai_messages = [
                'init' => exmtrans('ai_assistant.ai_response.suggested'),
                'confirming' => exmtrans('ai_assistant.ai_response.confirming'),
            ];
            $endpoint = $endpoints[$conversable->status] ?? 'store';
            $ai_message = $ai_messages[$conversable->status];
            $aiApiUrl = $this->aiAssistantServerUrl . $endpoint;

            $response = Http::withToken($this->bearerToken)->post($aiApiUrl, [
                'uuid' => $validated['uuid'],
                'message' => $validated['message'],
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $conversable->update([
                    'status' => $data['status'] ?? $conversable->status,
                    'table_draft_json' => $data['table_draft_json'] ?? null,
                    'column_draft_json' => $data['column_draft_json'] ?? null,
                ]);

                $conversable->messages()->create([
                    'message_text' => $ai_message . $data['message'],
                    'role' => 'assistant',
                ]);

                return response()->json([
                    'message' => $ai_message . $data['message'],
                    'showActionButtons' => !empty($conversable->table_draft_json),
                    'uuid' => $conversable->id,
                ]);
            }

            return response()->json(['message' => 'An error occurred while connecting to AI.'], 500);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }
    }

    public function handleAction(Request $request)
    {
        $validated = $request->validate([
            'uuid' => 'required|uuid',
            'action' => 'required|in:edit,create,cancel',
        ]);

        try {
            $conversable = $this->findConversable($validated['uuid']);
            if (!$conversable) {
                throw new ModelNotFoundException();
            }

            $responseMessage = '';
            $showActionButtons = false;

            switch ($validated['action']) {
                case 'edit':
                    $conversable->update(['status' => 'confirming']);
                    $responseMessage = exmtrans('ai_assistant.edit_message');
                    break;
                case 'create':
                    $response = Http::withToken($this->bearerToken)->post($this->aiAssistantServerUrl . 'confirm', [
                        'uuid' => $validated['uuid'],
                    ]);

                    if ($response->successful()) {
                        $data = $response->json();
                        $conversable->update([
                            'status' => $data['status'] ?? $conversable->status,
                            'table_draft_json' => $data['table_draft_json'] ?? null,
                            'column_draft_json' => $data['column_draft_json'] ?? null,
                        ]);
                        $ai_message = exmtrans('ai_assistant.ai_response.confirmed');
                        $responseMessage = $ai_message;
                        $this->createCustomTableFromDraft($data['table_draft_json'], $data['column_draft_json']);
                    }
                    break;
                case 'cancel':
                    $conversable->messages()->delete();
                    $conversable->delete();
                    break;
            }

            if ($validated['action'] !== 'cancel') {
                $conversable->messages()->create([
                    'message_text' => $responseMessage,
                    'role' => 'assistant',
                ]);
            }

            return response()->json([
                'message' => $responseMessage,
                'uuid' => $conversable->id ?? null,
                'showActionButtons' => $showActionButtons,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }
    }

    protected function createCustomTableFromDraft(array $tableDraftJson, array $columnDraftJson)
    {
        return DB::transaction(function () use ($tableDraftJson, $columnDraftJson) {
            $customTable = CustomTable::create([
                'table_name' => $tableDraftJson['table_name'],
                'table_view_name' => $tableDraftJson['table_view_name'],
                'options' => $tableDraftJson['options'],
            ]);

            foreach ($columnDraftJson as $column) {
                CustomColumn::create([
                    'custom_table_id' => $customTable->id,
                    'column_name' => $column['column_name'],
                    'column_view_name' => $column['column_view_name'],
                    'column_type' => $column['column_type'],
                    'options' => $column['options'],
                ]);
            }
        });
    }

    private function findConversable(string $uuid)
    {
        return AssistantTable::find($uuid);
    }
}
