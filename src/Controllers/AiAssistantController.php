<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Model\AssistantWorkflow;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\WorkflowTable;
use Exceedone\Exment\Model\Workflow;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Exceedone\Exment\Model\AssistantTable;
use Exceedone\Exment\Model\AssistantCalendar;
use Illuminate\Support\Facades\Http;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Exceedone\Exment\Services\WorkflowService;

use Encore\Admin\Layout\Content;

class AiAssistantController extends AdminControllerBase
{
    protected string $aiAssistantServerUrl;
    protected string $bearerToken;

    public function __construct()
    {
        $this->aiAssistantServerUrl = config('exment.ai_server_host') . '/api/ai_assistant/';
        $this->bearerToken          = config('exment.ai_server_api_key');
    }

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

        $loginUserID = \Exment::user()->id;
        $model = null;
        $welcomeMessage = '';

        switch ($validated['feature_type']) {
            case 'custom_table':
                AssistantTable::where('created_user_id', $loginUserID)->delete();
                $model = AssistantTable::create(['status' => 'init']);
                $welcomeMessage = exmtrans('ai_assistant.welcome_message', ['type' => exmtrans('ai_assistant.feature.custom_table')]);
                break;
            case 'workflow':
                AssistantWorkflow::where('created_user_id', $loginUserID)->delete();
                $model = AssistantWorkflow::create(['status' => 'init']);
                $userCustomTables = $this->getUserCustomTablesAvaiable();
                $welcomeMessage = exmtrans('ai_assistant.ai_response.workflow.welcome');
                $welcomeMessage .= "\r\n" . implode("\n", $userCustomTables);
                break;
            case 'calendar':
                AssistantCalendar::where('created_user_id', $loginUserID)->delete();
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
                $responseMessage = $this->handleSendMessageWorkflow($validated['uuid'], $validated['message'], $conversable);
            } elseif ($featureType == 'calendar') {
                $responseMessage = $this->handleSendMessageCalendar($validated['uuid'], $validated['message'], $conversable);
            }

            $conversable->messages()->create([
                'message_text' => $responseMessage,
                'role' => 'assistant',
            ]);

            $isError = in_array($responseMessage, [
                exmtrans('ai_assistant.error_message'),
                exmtrans('ai_assistant.error_unauthorized'),
                exmtrans('ai_assistant.error_subscription_required'),
                exmtrans('ai_assistant.error_service_not_found'),
                exmtrans('ai_assistant.error_api_limit_exceeded'),
                exmtrans('ai_assistant.ai_response.workflow.request_table'),
                exmtrans('ai_assistant.ai_response.custom_table.table_exists'),
                exmtrans('ai_assistant.ai_response.workflow.workflow_exists'),
            ], true);
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
            $editUrl = null;

            switch ($validated['action']) {
                case 'edit':
                    if ($featureType == 'custom_table') {
                        if ($conversable->status === 'explained') {
                            $conversable->update(['status' => 'init']);
                        } else {
                            $conversable->update(['status' => 'confirming']);
                        }
                    } elseif ($featureType == 'workflow') {
                        if ($conversable->status === 'suggested_workflow') {
                            $conversable->update(['status' => 'confirming_workflow']);
                        } elseif ($conversable->status === 'suggested_actions') {
                            $conversable->update(['status' => 'confirming_actions']);
                        }
                    }
                    $responseMessage = exmtrans('ai_assistant.edit_message');
                    break;
                case 'create':
                    if ($featureType == 'custom_table') {
                        if ($conversable->status === 'explained') {
                            $responseMessage = $this->handleSendMessageCustomTable($validated['uuid'], "", $conversable);
                            if ($responseMessage !== exmtrans('ai_assistant.ai_response.custom_table.table_exists')) {
                                $showActionButtons = true;
                            }
                        } else {
                            $result = $this->handleActionCreateCustomTable(
                                $validated['uuid'],
                                $conversable
                            );
                            $responseMessage = $result['message'];
                            $customTableID = $result['tableID'] ?? null;
                            $editUrl = $customTableID
                                ? route('exment.table.edit', $customTableID)
                                : null;
                        }
                    } elseif ($featureType == 'workflow') {
                        if ($conversable->status === 'suggested_workflow' || $conversable->status === 'confirming_workflow') {
                            $conversable->update(['status' => 'request_actions']);
                            $responseMessage = $this->handleSendMessageWorkflow($validated['uuid'], "", $conversable);
                            if ($responseMessage !== exmtrans('ai_assistant.ai_response.workflow.workflow_exists')) {
                                $showActionButtons = true;
                            }
                        } elseif ($conversable->status === 'confirming_actions') {
                            $conversable->update(['status' => 'confirming']);
                            $responseMessage = exmtrans('ai_assistant.edit_message');
                        } elseif ($conversable->status === 'suggested_actions' || $conversable->status === 'confirming') {
                            $conversable->update(['status' => 'confirmed']);
                            $result = $this->handleActionCreateWorkFlow(
                                $validated['uuid'],
                                $conversable
                            );
                            $responseMessage = $result['message'];
                            $workflowID = $result['workflowID'] ?? null;
                            $editUrl = $workflowID
                                ? route('exment.workflow.edit', $workflowID)
                                : null;
                        }
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
                'confirmEditUrl' => $editUrl,
                'uuid' => $conversable->id ?? null,
                'showActionButtons' => $showActionButtons,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }
    }

    protected  function handleSendMessageCustomTable(string $uuid, string $message, AssistantTable $assistant_table): ?string {
        $endpoints = [
            'init' => 'explain',
            'explained' => 'store',
            'confirming' => 'edit',
        ];
        $ai_messages = [
            'init' => exmtrans('ai_assistant.ai_response.custom_table.explained'),
            'explained' => exmtrans('ai_assistant.ai_response.custom_table.suggested'),
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

            // Check Table Name
            $allCustomTables = $this->getAllCustomTables();
            $tableName = data_get($data, 'table_draft_json.table_name')
                ?? data_get($data, 'table_name');
            if ($tableName && in_array($tableName, $allCustomTables, true)) {
                return exmtrans('ai_assistant.ai_response.custom_table.table_exists');
            }

            $assistant_table->update([
                'status' => $data['status'] ?? $assistant_table->status,
                'table_draft_json' => $data['table_draft_json'] ?? null,
                'column_draft_json' => $data['column_draft_json'] ?? null,
            ]);

            return $ai_message . "\n" . $data['message'];
        }

        // return exmtrans('ai_assistant.error_message');
        return $this->mapAiAssistantErrorResponse($response);
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

            return $ai_message . "\n" . $data['message'];
        }

        // return exmtrans('ai_assistant.error_message');
        return $this->mapAiAssistantErrorResponse($response);
    }

    protected  function handleSendMessageWorkflow(string $uuid, string $message, AssistantWorkflow $assistant_workflow): ?string {
        $endpoints = [
            'init' => 'store',
            'confirming_workflow' => 'store',
            'request_actions' => 'store',
            'confirming_actions' => 'store',
            'confirming' => 'edit',
        ];
        $ai_messages = [
            'init' => exmtrans('ai_assistant.ai_response.workflow.suggested', ['type' => "WorkFlow"]),
            'confirming_workflow' => exmtrans('ai_assistant.ai_response.workflow.suggested', ['type' => "WorkFlow"]),
            'request_actions' => exmtrans('ai_assistant.ai_response.workflow.suggested', ['type' => "Actions"]),
            'confirming_actions' => exmtrans('ai_assistant.ai_response.workflow.suggested', ['type' => "Actions"]),
            'confirming' => exmtrans('ai_assistant.ai_response.workflow.confirming'),
        ];
        $endpoint = $endpoints[$assistant_workflow->status] ?? 'store';
        $ai_message = $ai_messages[$assistant_workflow->status];
        $aiApiUrl = $this->aiAssistantServerUrl . 'assistant-workflow/' . $endpoint;


        $payload = [
            'uuid' => $uuid,
            'message' => $message,
            'status' => $assistant_workflow->status,
        ];

        if ($assistant_workflow->status === 'init') {
            $userCustomTables = $this->getUserCustomTablesAvaiable();
            $payload['available_tables'] = json_encode($userCustomTables, JSON_THROW_ON_ERROR);
        }

        $response = Http::withToken($this->bearerToken)->post($aiApiUrl, $payload);

        if ($response->successful()) {
            $data = $response->json();

            // Request Create New Table
            if ($data['status'] === 'request_table') {
                return exmtrans('ai_assistant.ai_response.workflow.request_table');
            }

            // Check WorkFlow View Name
            $allWorkFlowViewName = $this->getAllWorkFlows();
            if (isset($data['workflow_draft']['workflow_view_name']) &&
                in_array($data['workflow_draft']['workflow_view_name'], $allWorkFlowViewName, true))
            {
                return exmtrans('ai_assistant.ai_response.workflow.workflow_exists');
            }

            $assistant_workflow->update([
                'status' => $data['status'] ?? $assistant_workflow->status,
                'workflow_table_name_draft' => $data['table_name_draft'] ?? $assistant_workflow->workflow_table_name_draft,
                'workflow_draft_json' => $data['workflow_draft'] ?? $assistant_workflow->workflow_draft_json,
                'workflow_statuses_draft_json' => $data['workflow_statuses_draft'] ?? $assistant_workflow->workflow_statuses_draft_json,
                'workflow_actions_draft_json' => $data['workflow_actions_draft'] ?? $assistant_workflow->workflow_actions_draft_json,
            ]);

            return $ai_message . "\n" . $data['message'];
        }

        // return exmtrans('ai_assistant.error_message');
        return $this->mapAiAssistantErrorResponse($response);
    }

    protected function handleActionCreateCustomTable(string $uuid, AssistantTable $assistant_table) {
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

            $table = $this->createCustomTableFromDraft($data['table_draft_json'], $data['column_draft_json']);

            return [
                'message' => exmtrans('ai_assistant.ai_response.custom_table.confirmed'),
                'tableID' => $table->id,
            ];
        }

        return [
            // 'message' => exmtrans('ai_assistant.error_message'),
            'message' => $this->mapAiAssistantErrorResponse($response),
            'tableID' => null,
        ];
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

        return exmtrans('ai_assistant.error_message');
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

            return $customTable;
        });
    }

    protected function handleActionCreateWorkFlow(string $uuid, AssistantWorkflow $assistant_workflow) {
        $response = Http::withToken($this->bearerToken)->post($this->aiAssistantServerUrl . 'assistant-workflow/' . 'confirm', [
            'uuid' => $uuid,
        ]);

        if ($response->successful()) {
            $data = $response->json();

            $assistant_workflow->update([
                'status' => $data['status'] ?? $assistant_workflow->status,
                'workflow_table_name_draft' => $data['table_name_draft'] ?? $assistant_workflow->workflow_table_name_draft,
                'workflow_draft_json' => $data['workflow_draft'] ?? $assistant_workflow->workflow_draft_json,
                'workflow_statuses_draft_json' => $data['workflow_statuses_draft'] ?? $assistant_workflow->workflow_statuses_draft_json,
                'workflow_actions_draft_json' => $data['workflow_actions_draft'] ?? $assistant_workflow->workflow_actions_draft_json,
            ]);

            $workflow = $this->createWorkFlowFromDraft($assistant_workflow);

            return [
                'message' => exmtrans('ai_assistant.ai_response.workflow.confirmed'),
                'workflowID' => $workflow->id,
            ];
        }

        return [
            // 'message' => exmtrans('ai_assistant.error_message'),
            'message' => $this->mapAiAssistantErrorResponse($response),
            'workflowID' => null,
        ];
    }

    protected function createWorkFlowFromDraft(AssistantWorkflow $assistant_workflow)
    {
        $workflow_table_name_draft = $assistant_workflow->workflow_table_name_draft;
        $workflow_draft = $assistant_workflow->workflow_draft_json;
        $workflow_statuses_draft = $assistant_workflow->workflow_statuses_draft_json;
        $workflow_actions_draft = $assistant_workflow->workflow_actions_draft_json;

        return DB::transaction(function () use ($workflow_table_name_draft, $workflow_draft, $workflow_statuses_draft, $workflow_actions_draft) {
            $workflow = WorkflowService::createWorkflowForTable(
                $workflow_table_name_draft,
                $workflow_draft['workflow_view_name'],
                $workflow_draft['start_status_name'],
                $workflow_statuses_draft
            );

            if ($workflow) {
                WorkflowService::insertActionsAndCompleteSetting($workflow, $workflow_actions_draft);
            }

            return $workflow;
        });
    }

    private function findConversable(string $uuid, string $type)
    {
        if ($type == 'custom_table') {
            return AssistantTable::find($uuid);
        } elseif ($type == 'workflow') {
            return AssistantWorkflow::find($uuid);
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

    private function getUserCustomTablesAvaiable(): array
    {
        $loginUser = \Exment::user();

        // CustomTable has Workflow List
        $excludedIds = WorkflowTable::query()
            ->pluck('custom_table_id')
            ->toArray();

        // User's CustomTable not have Workflow
        return CustomTable::query()
            ->where('created_user_id', $loginUser->id)
            ->whereNotIn('id', $excludedIds)
            ->pluck('table_name')
            ->toArray();
    }

    private function getAllCustomTables(): array
    {
        return CustomTable::query()
            ->pluck('table_name')
            ->toArray();
    }

    private function getAllWorkFlows(): array
    {
        return Workflow::query()
            ->pluck('workflow_view_name')
            ->toArray();
    }

    protected function mapAiAssistantErrorResponse($response): string
    {
        $status = $response->status();
        $upstreamMessage = $response->json('message');

        \Log::warning('AI Assistant upstream error', [
            'status' => $status,
            'message' => $upstreamMessage,
        ]);

        return match ($status) {
            401 => exmtrans('ai_assistant.error_unauthorized'),
            403 => exmtrans('ai_assistant.error_subscription_required'),
            404 => exmtrans('ai_assistant.error_service_not_found'),
            429 => exmtrans('ai_assistant.error_api_limit_exceeded'),
            default => exmtrans('ai_assistant.error_message'),
        };
    }
}
