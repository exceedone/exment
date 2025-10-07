<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Exceedone\Exment\Model\AssistantTable;
use Exceedone\Exment\Model\AssistantCalendar;
use Illuminate\Support\Facades\Http;
use Illuminate\Database\Eloquent\ModelNotFoundException;

use Encore\Admin\Layout\Content;

class AiAssistantController extends AdminControllerBase
{
    protected string $aiAssistantServerUrl = 'https://exment.org/api/ai_assistant/';
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
            'feature_type' => 'required|in:custom_table,workflow,calendar',
        ]);

        $model = null;
        $welcomeMessage = '';

        switch ($validated['feature_type']) {
            case 'custom_table':
                $model = AssistantTable::create(['status' => 'init']);
                $welcomeMessage = exmtrans('ai_assistant.welcome_message', ['type' => exmtrans('ai_assistant.feature.custom_table')]);
                break;
            case 'workflow':
            case 'calendar':
                $usersAndOrgs = $this->getOrganizationUsers();
                $model = AssistantCalendar::create(['status' => 'init']);
                $welcomeMessage = exmtrans('ai_assistant.ai_response.calendar.welcome', ['type' => exmtrans('ai_assistant.feature.schedule_notifications')]);
                $welcomeMessage .= "\r\n" . $this->formatOrganizationUsersAsString($usersAndOrgs);
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
            'feature_type' => 'required|in:custom_table,workflow,calendar',
        ]);

        $featureType = $validated['feature_type'];

        try {
            $responseMessage = '';
            $conversable = $this->findConversable($validated['uuid'], $featureType);
            if (!$conversable) {
                throw new ModelNotFoundException();
            }

            $conversable->messages()->create([
                'message_text' => $validated['message'],
                'role' => 'user',
            ]);

            if ($featureType == 'custom_table') {
                $responseMessage = $this->handleSendMessageCustomTable($validated['uuid'], $validated['message'], $conversable);
            } elseif ($featureType == 'workflow') {
                $responseMessage = 'An error occurred while connecting to AI.';
            } elseif ($featureType == 'calendar') {
                $responseMessage = $this->handleSendMessageCalendar($validated['uuid'], $validated['message'], $conversable);
            }

            $conversable->messages()->create([
                'message_text' => $responseMessage,
                'role' => 'assistant',
            ]);

            $isError = $responseMessage === 'An error occurred while connecting to AI.';
            return response()->json([
                'message' => $responseMessage,
                'showActionButtons' => !$isError,
                'uuid' => $conversable->id,
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }
    }

    public function handleAction(Request $request)
    {
        $validated = $request->validate([
            'uuid' => 'required|uuid',
            'action' => 'required|in:edit,create,cancel',
            'feature_type' => 'required|in:custom_table,workflow,calendar',
        ]);
        $featureType = $validated['feature_type'];

        try {
            $conversable = $this->findConversable($validated['uuid'], $featureType);
            if (!$conversable) {
                throw new ModelNotFoundException();
            }

            $responseMessage = '';
            $showActionButtons = false;

            switch ($validated['action']) {
                case 'edit':
                    $responseMessage = exmtrans('ai_assistant.edit_message');
                    break;
                case 'create':
                    if ($featureType == 'custom_table') {
                        $responseMessage = $this->handleActionCreateCustomTable($validated['uuid'], $conversable);
                    } elseif ($featureType == 'workflow') {
                        $responseMessage = null;
                    } elseif ($featureType == 'calendar') {
                        $responseMessage = $this->handleActionCreateCalendar($validated['uuid'], $conversable);
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

    protected  function handleSendMessageCustomTable(string $uuid, string $message, AssistantTable $assistant_table): ?string {
        $endpoints = [
            'init' => 'store',
            'confirming' => 'edit',
        ];
        $ai_messages = [
            'init' => exmtrans('ai_assistant.ai_response.custom_table.suggested'),
            'confirming' => exmtrans('ai_assistant.ai_response.custom_table.confirming'),
        ];
        $endpoint = $endpoints[$assistant_table->status] ?? 'store';
        $ai_message = $ai_messages[$assistant_table->status];
        $aiApiUrl = $this->aiAssistantServerUrl . 'assistant-tables/' . $endpoint;

        $response = Http::withToken($this->bearerToken)->post($aiApiUrl, [
            'uuid' => $uuid,
            'message' => $message,
        ]);

        if ($response->successful()) {
            $data = $response->json();

            $assistant_table->update([
                'status' => $data['status'] ?? $assistant_table->status,
                'table_draft_json' => $data['table_draft_json'] ?? null,
                'column_draft_json' => $data['column_draft_json'] ?? null,
            ]);

            return $ai_message . $data['message'];
        }

        return 'An error occurred while connecting to AI.';
    }

    protected  function handleSendMessageCalendar(string $uuid, string $message, AssistantCalendar $assistant_calendar): ?string {
        $endpoints = [
            'init' => 'store',
            'confirming_request_calendar' => 'store',
            'confirming_request_notify' => 'store',
            'confirming_user_calendar' => 'edit',
            'confirming_user_notify' => 'edit',
        ];
        $ai_messages = [
            'init' => exmtrans('ai_assistant.ai_response.calendar.suggested', ['type' => exmtrans('ai_assistant.feature.schedule_notifications')]),
            'confirming_request_calendar' => exmtrans('ai_assistant.ai_response.calendar.suggested', ['type' => exmtrans('ai_assistant.feature.calendar')]),
            'confirming_request_notify' => exmtrans('ai_assistant.ai_response.calendar.suggested', ['type' => exmtrans('ai_assistant.feature.notify')]),
            'confirming_user_calendar' => exmtrans('ai_assistant.ai_response.calendar.confirming', ['type' => exmtrans('ai_assistant.feature.calendar')]),
            'confirming_user_notify' => exmtrans('ai_assistant.ai_response.calendar.confirming', ['type' => exmtrans('ai_assistant.feature.notify')]),
        ];
        $endpoint = $endpoints[$assistant_calendar->status] ?? 'store';
        $ai_message = $ai_messages[$assistant_calendar->status];
        $aiApiUrl = $this->aiAssistantServerUrl . 'assistant-calendar/' . $endpoint;


        $payload = [
            'uuid' => $uuid,
            'message' => $message,
        ];

        if ($assistant_calendar->status === 'init') {
            $usersAndOrgs = $this->getOrganizationUsers();
            $payload['organization_users'] = json_encode($usersAndOrgs, JSON_THROW_ON_ERROR);
        }

        $response = Http::withToken($this->bearerToken)->post($aiApiUrl, $payload);

        if ($response->successful()) {
            $data = $response->json();

            $assistant_calendar->update([
                'status' => $data['status'] ?? $assistant_calendar->status,
            ]);

            return $ai_message . $data['message'];
        }

        return 'An error occurred while connecting to AI.';
    }

    protected function handleActionCreateCustomTable(string $uuid, AssistantTable $assistant_table): ?string {
        $response = Http::withToken($this->bearerToken)->post($this->aiAssistantServerUrl . 'assistant-tables/' . 'confirm', [
            'uuid' => $uuid,
        ]);

        if ($response->successful()) {
            $data = $response->json();

            $assistant_table->update([
                'status' => $data['status'] ?? $assistant_table->status,
                'table_draft_json' => $data['table_draft_json'] ?? null,
                'column_draft_json' => $data['column_draft_json'] ?? null,
            ]);

            $this->createCustomTableFromDraft($data['table_draft_json'], $data['column_draft_json']);

            return exmtrans('ai_assistant.ai_response.custom_table.confirmed');
        }

        return 'An error occurred while connecting to AI.';
    }

    protected function handleActionCreateCalendar(string $uuid, AssistantCalendar $assistant_calendar): ?string {
        $login_user = \Exment::user();

        $response = Http::withToken($this->bearerToken)->post($this->aiAssistantServerUrl . 'assistant-calendar/' . 'confirm', [
            'uuid' => $uuid,
            'requester_name' => $login_user->name,
            'requester_email' => $login_user->email,
        ]);

        if ($response->successful()) {
            $data = $response->json();

            $assistant_calendar->update([
                'status' => $data['status'] ?? $assistant_calendar->status,
            ]);

            return exmtrans('ai_assistant.ai_response.calendar.confirmed');
        }

        return 'An error occurred while connecting to AI.';
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

    private function findConversable(string $uuid, string $type)
    {
        if ($type == 'custom_table') {
            return AssistantTable::find($uuid);
        } elseif ($type == 'workflow') {
            return null;
        } elseif ($type == 'calendar') {
            return AssistantCalendar::find($uuid);
        }
    }

    private function getOrganizationUsers()
    {
        $organizationUsers = [];
        $users = \Exment::user()->base_user::all();

        foreach ($users as $user) {
            $userName = $user->value['user_name'] ?? null;
            $email = $user->value['email'] ?? null;

            if ($user->belong_organizations->isNotEmpty()) {
                foreach ($user->belong_organizations as $org) {
                    $orgName = $org->value['organization_name'] ?? 'unknown';

                    if (!isset($organizationUsers[$orgName])) {
                        $organizationUsers[$orgName] = [
                            'organization' => $orgName,
                            'users' => []
                        ];
                    }

                    $organizationUsers[$orgName]['users'][] = [
                        'user_name' => $userName,
                        'email' => $email
                    ];
                }
            } else {
                $orgName = 'unknown';

                if (!isset($organizationUsers[$orgName])) {
                    $organizationUsers[$orgName] = [
                        'organization' => $orgName,
                        'users' => []
                    ];
                }
                $organizationUsers[$orgName]['users'][] = [
                    'user_name' => $userName,
                    'email' => $email
                ];
            }
        }

        $login_user = \Exment::user();
        $organizationUsers['requester'] = [
            'organization' => 'requester',
            'users' => [
                'user_name' => $login_user->name,
                'email' => $login_user->email
            ]
        ];

        return array_values($organizationUsers);
    }

    private function formatOrganizationUsersAsString(array $usersAndOrgs): string
    {
        $lines = [];
        foreach ($usersAndOrgs as $group) {
            if (isset($group['organization']) && $group['organization'] === 'requester') {
                continue;
            }

            $orgName = $group['organization'];
            $userNames = array_map(function ($user) {
                return $user['user_name'];
            }, $group['users']);

            $line = '+ ' . $orgName . ': ' . implode(', ', $userNames);
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }
}
