<?php

namespace Exceedone\Exment\Controllers;

use ExmentAdminCore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Model\WorkflowAction;
use Exceedone\Exment\Model\WorkflowStatus;
use Exceedone\Exment\Model\WorkflowTable;
use Exceedone\Exment\Model\WorkflowValue;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\ConditionTypeDetail;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\WorkflowCommentType;
use Exceedone\Exment\Enums\WorkflowNextType;
use Exceedone\Exment\Enums\WorkflowTargetSystem;
use Exceedone\Exment\Enums\WorkflowType;
use Exceedone\Exment\Enums\WorkflowWorkTargetType;

/**
 * ワークフロー デザイナー（フロー図を直接編集する画面）。
 *
 * ステップ1（ステータス設定）とステップ2（アクション設定）で入力する内容を、
 * 1枚のキャンバス上で編集する。保存先はステップ1・2とまったく同じテーブル
 * （workflow_statuses / workflow_actions / workflow_authorities /
 * workflow_condition_headers）なので、どちらの画面から編集しても結果は同じになる。
 *
 * WorkflowController が大きくなりすぎないよう、また PHP 8.3 + OPcache で
 * 巨大ファイルを読み込む際の不具合を避けるため、トレイトとして切り出している。
 */
trait WorkflowDesignTrait
{
    /**
     * 実行可能ユーザー（work_targets）で使うキー。
     * WorkflowAuthority.related_type に入る値と同じもの。
     *
     * @return array<string>
     */
    protected static function designTargetKeys(): array
    {
        return [
            ConditionTypeDetail::USER()->lowerKey(),               // user
            ConditionTypeDetail::ORGANIZATION()->lowerKey(),       // organization
            ConditionTypeDetail::COLUMN()->lowerKey(),             // column
            ConditionTypeDetail::SYSTEM()->lowerKey(),             // system
            ConditionTypeDetail::LOGIN_USER_COLUMN()->lowerKey(),  // login_user_column
        ];
    }

    /**
     * デザイナー画面を表示する。
     *
     * @param Request $request
     * @param Content $content
     * @param mixed $id
     * @return Content
     */
    // @phpstan-ignore-next-line
    public function design(Request $request, Content $content, $id)
    {
        $workflow = Workflow::getEloquent($id);
        if (!isset($workflow)) {
            abort(404);
        }

        $this->setPageInfo(
            exmtrans('workflow.design.header'),
            exmtrans('workflow.design.header'),
            exmtrans('workflow.design.description'),
            'fa-sitemap'
        );

        $content = $this->AdminContent($content);
        $content->row(view('exment::workflow.design', [
            'design_data' => $this->getWorkflowDesignData($workflow),
        ])->render());

        return $content;
    }

    /**
     * デザイナーで新しいワークフローを作る画面。
     *
     * ステップ1 → ステップ2 と順に入力する代わりに、空のキャンバスから始めて
     * 保存（wfd-save）時に workflows のレコードごと作る。作った直後は
     * workflow/{id}/design へ移り、ステップ3・ステップ4 が押せる状態になる。
     *
     * @param Request $request
     * @param Content $content
     * @return Content
     */
    // @phpstan-ignore-next-line
    public function designCreate(Request $request, Content $content)
    {
        $workflow = new Workflow();
        // 種類は画面で選ばせる。未選択のまま getDesignatedTable() を呼ばれても
        // null が返るよう、既定値だけ入れておく。
        $workflow->workflow_type = WorkflowType::COMMON;

        $this->setPageInfo(
            exmtrans('workflow.design.header'),
            exmtrans('workflow.design.new_workflow'),
            exmtrans('workflow.design.new_description'),
            'fa-sitemap'
        );

        $content = $this->AdminContent($content);
        $content->row(view('exment::workflow.design', [
            'design_data' => $this->getWorkflowDesignData($workflow),
        ])->render());

        return $content;
    }

    /**
     * ステップ1・ステップ2の画面に「デザイナーで編集」ボタンを足す。
     * 新規作成中（$workflow が無い）ときは、まだ図に出すものが無いので出さない。
     *
     * @param Workflow|null $workflow
     * @param mixed $tools
     * @return void
     */
    // @phpstan-ignore-next-line
    public function appendDesignButton($workflow, $tools)
    {
        if (!isset($workflow) || is_nullorempty($workflow->id)) {
            return;
        }

        $tools->append(view('exment::tools.button', [
            'href' => admin_urls('workflow', $workflow->id, 'design'),
            'label' => exmtrans('workflow.design.header'),
            'icon' => 'fa-sitemap',
            'btn_class' => 'btn-info',
        ]));
    }

    /**
     * デザイナーの内容を保存する。ajax（JSON）専用。
     *
     * @param Request $request
     * @param mixed $id
     * @return \Illuminate\Http\JsonResponse
     */
    // @phpstan-ignore-next-line
    public function designPost(Request $request, $id)
    {
        $workflow = Workflow::getEloquent($id);
        if (!isset($workflow)) {
            abort(404);
        }

        if (($deny = $this->denyWorkflowDesign()) !== null) {
            return $deny;
        }

        $payload = $this->readDesignPayload($request);

        // 別のタブや他の利用者が先に保存していたら、上書きせずに知らせる
        $posted_at = array_get($payload, 'updated_at');
        if (!is_nullorempty($posted_at) && strval($workflow->updated_at) !== strval($posted_at)) {
            $message = exmtrans('workflow.design.message.conflict');
            return response()->json([
                'result' => false,
                'toastr' => $message,
                'errors' => [$message],
            ], 409);
        }

        $errors = $this->validateWorkflowDesign($workflow, $payload);
        if (count($errors) > 0) {
            return response()->json([
                'result' => false,
                'toastr' => exmtrans('workflow.design.message.validation_error'),
                'errors' => $errors,
            ], 422);
        }

        return response()->json([
            'result' => true,
            'toastr' => trans('admin.save_succeeded'),
            'data' => $this->saveWorkflowDesign($workflow, $payload),
        ]);
    }

    /**
     * デザイナーから新しいワークフローを作る。ajax（JSON）専用。
     *
     * ステップ1 の保存と同じものを作る（workflows ＋ 使用テーブル ＋ ステータス）
     * ので、作ったあとはステップ1・ステップ2 の画面からも普通に編集できる。
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    // @phpstan-ignore-next-line
    public function designCreatePost(Request $request)
    {
        if (($deny = $this->denyWorkflowDesign()) !== null) {
            return $deny;
        }

        $payload = $this->readDesignPayload($request);

        $workflow = new Workflow();
        $workflow->workflow_type = strval(array_get($payload, 'workflow_type', WorkflowType::COMMON));

        $errors = $this->validateWorkflowDesign($workflow, $payload);
        if (count($errors) > 0) {
            return response()->json([
                'result' => false,
                'toastr' => exmtrans('workflow.design.message.validation_error'),
                'errors' => $errors,
            ], 422);
        }

        $data = $this->saveWorkflowDesign($workflow, $payload, array_get($payload, 'custom_table_id'));
        // 作ったワークフローの画面へ移る。ここで初めてステップ1〜4 が開けるようになる。
        $data['redirect'] = admin_urls('workflow', $workflow->id, 'design');

        return response()->json([
            'result' => true,
            'toastr' => trans('admin.save_succeeded'),
            'data' => $data,
        ]);
    }

    /**
     * ワークフロー権限が無ければ、その場で返す JSON を作る。あれば null。
     *
     * @return \Illuminate\Http\JsonResponse|null
     */
    protected function denyWorkflowDesign()
    {
        if (\Exment::user()->hasPermission(Permission::WORKFLOW)) {
            return null;
        }

        return response()->json([
            'result' => false,
            'toastr' => trans('admin.deny'),
            'errors' => [trans('admin.deny')],
        ], 403);
    }

    /**
     * 画面から送られてきた design（JSON 文字列）を配列にする。
     *
     * @param Request $request
     * @return array
     */
    protected function readDesignPayload(Request $request): array
    {
        $payload = $request->input('design');
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }

        return (array)$payload;
    }

    /**
     * 図の内容をまとめて保存する。新規（$workflow->exists が false）でも同じ流れ。
     *
     * @param Workflow $workflow
     * @param array $payload
     * @param mixed $custom_table_id 新規かつテーブル専用のときに使う対象テーブル
     * @return array 画面へ返す内容
     */
    protected function saveWorkflowDesign(Workflow $workflow, array $payload, $custom_table_id = null): array
    {
        $status_map = [];
        $action_map = [];

        \ExmentDB::transaction(function () use ($workflow, $payload, $custom_table_id, &$status_map, &$action_map) {
            $isNew = !$workflow->exists;

            $workflow->workflow_view_name = trim(strval(array_get($payload, 'workflow_view_name')));
            $workflow->start_status_name = trim(strval(array_get($payload, 'start_status_name')));
            $workflow->save();

            // 使用テーブルはステップ1 の保存と同じで、新規作成時に1件だけ作る
            if ($isNew && $workflow->workflow_type == WorkflowType::TABLE && !is_nullorempty($custom_table_id)) {
                WorkflowTable::create([
                    'custom_table_id' => $custom_table_id,
                    'workflow_id' => $workflow->id,
                ]);
            }

            $status_map = $this->saveDesignStatuses($workflow, array_get($payload, 'statuses', []));
            $action_map = $this->saveDesignActions($workflow, array_get($payload, 'actions', []), $status_map);

            $this->saveDesignLayout($workflow, array_get($payload, 'layout', []), $status_map);
        });

        System::clearCache();

        // 保存でステータスやアクションが増えると、ステップ3 が押せる状態に変わる。
        // 読み直したものから判定して、画面のボタンを更新できるようにする。
        $workflow->unsetRelations();

        return [
            'updated_at' => strval($workflow->updated_at),
            'status_map' => $status_map,
            'action_map' => $action_map,
            'activated' => boolval($workflow->setting_completed_flg),
            'can_activate' => boolval($workflow->canActivate()),
        ];
    }

    /* =====================================================================
     *  画面へ渡すデータ
     * ================================================================== */

    /**
     * デザイナーが必要とするデータを1つの配列にまとめる。
     *
     * @param Workflow $workflow
     * @return array
     */
    protected function getWorkflowDesignData(Workflow $workflow): array
    {
        // 新規作成（まだ workflows に無い）ときは、関連テーブルを引く相手がいない
        $isNew = !$workflow->exists;
        $isTable = $workflow->workflow_type == WorkflowType::TABLE;
        $custom_table = $isNew ? null : $workflow->getDesignatedTable();
        // 設定完了済みでも、追加・並べ替え・改名は既存データを壊さないので許す。
        // 壊れるのは「使用中ステータスの削除」だけ（workflow_values ごと消える）なので、
        // ステップ1 のような一律ロックはせず、そのステータスだけ削除できないようにする。
        $activated = boolval($workflow->setting_completed_flg);

        $statuses = $isNew ? collect() : $workflow->workflow_statuses()->get();
        $in_use_ids = $this->getUsedStatusIds($statuses->pluck('id')->all());
        $actions = $isNew ? collect() : $workflow->workflow_actions()->orderBy('id')->get();

        // 完了ステータスは 1 件に限らない。「承認済み」と「却下」のように終わりが
        // 複数あるフローは普通にあり、実行時も status ごとの completed_flg を見ている。
        // ステップ1 だけが「表の最終行＝完了」という作りなので、そこは注意書きで補う。
        $completed_count = $statuses->filter(function ($status) {
            return boolval($status->completed_flg);
        })->count();

        $status_data = $statuses->map(function ($status) use ($in_use_ids) {
            return [
                'id' => strval($status->id),
                'name' => strval($status->status_name),
                'datalock' => boolval($status->datalock_flg),
                'completed' => boolval($status->completed_flg),
                // 申請中・履歴で使われているステータスは削除させない
                'in_use' => in_array(strval($status->id), $in_use_ids, true),
            ];
        })->values()->toArray();

        // 実行可能ユーザーの選択肢は、全アクション分をまとめて 1 回だけ引く
        $selected = [];
        foreach (static::designTargetKeys() as $key) {
            $selected[$key] = [];
        }

        $targets_by_action = [];
        foreach ($actions as $action) {
            $target = $action->work_targets->toArray();
            $targets_by_action[$action->id] = $target;

            foreach (static::designTargetKeys() as $key) {
                foreach ((array)array_get($target, $key, []) as $v) {
                    $selected[$key][] = strval($v);
                }
            }
        }

        $action_data = $actions->map(function ($action) use ($targets_by_action) {
            $target = $targets_by_action[$action->id];

            $targets = [];
            foreach (static::designTargetKeys() as $key) {
                $targets[$key] = array_map('strval', array_values((array)array_get($target, $key, [])));
            }

            return [
                'id' => strval($action->id),
                'name' => strval($action->action_name),
                'status_from' => strval($action->status_from),
                'ignore_work' => boolval($action->ignore_work),
                'work_target_type' => strval(array_get($target, 'work_target_type') ?: WorkflowWorkTargetType::FIX),
                'targets' => $targets,
                'flow_next_type' => strval($action->flow_next_type ?: WorkflowNextType::SOME),
                'flow_next_count' => strval($action->flow_next_count ?? 1),
                'comment_type' => strval($action->comment_type ?: WorkflowCommentType::NULLABLE),
                // 分岐（実行後ステータス）。絞り込み条件はこの画面では編集しないが、
                // そのまま送り返して保存するので消えない。
                'destinations' => $this->toDesignDestinations($action->work_conditions),
            ];
        })->values()->toArray();

        return [
            'id' => strval($workflow->id),
            'workflow_view_name' => strval($workflow->workflow_view_name),
            'start_status_name' => strval($workflow->start_status_name),
            'workflow_type' => strval($workflow->workflow_type),
            'is_table' => $isTable,
            // 新規作成モード。保存すると workflows のレコードごと作る。
            'is_new' => $isNew,
            'activated' => $activated,
            // ステップ3（設定完了）を実行できるか。ステップ1・2 のツールバーと同じ判定。
            'can_activate' => $isNew ? false : boolval($workflow->canActivate()),
            // 完了が複数。この画面では保てるが、ステップ1で保存すると1件に絞られる
            'multi_completed' => $completed_count > 1,
            'updated_at' => strval($workflow->updated_at),
            // 分岐の最大本数。ステップ2の条件モーダルと同じ（テーブル専用=3 / 汎用=1）
            'max_branch' => $isTable ? 3 : 1,
            'urls' => [
                // 新規はワークフローが無いので、保存先も各ステップも別扱いにする
                'save' => $isNew
                    ? admin_url('workflow/design/create')
                    : admin_urls('workflow', $workflow->id, 'design'),
                'list' => admin_url('workflow'),
                'step1' => $isNew ? null : admin_urls('workflow', $workflow->id, 'edit?action=1'),
                'step2' => $isNew ? null : admin_urls('workflow', $workflow->id, 'edit?action=2'),
                // ステップ3 はモーダル（合言葉の入力が要る）。ステップ4 はワークフロー共通の画面。
                'step3' => $isNew ? null : admin_urls('workflow', $workflow->id, 'activateModal'),
                // 設定完了したあとのステップ3 は「通知設定」に変わる（一覧のベルと同じ画面）
                'notify' => $isNew ? null : admin_urls('workflow', $workflow->id, 'notify'),
                'step4' => admin_url('workflow/beginning'),
            ],
            'statuses' => $status_data,
            'actions' => $action_data,
            'layout' => $this->getWorkflowDesignLayout($workflow),
            'options' => $this->getWorkflowDesignOptions($workflow, $custom_table, $selected),
            // 新規のときだけ、種類と対象テーブルを画面で選ばせる
            'create_options' => $isNew ? $this->getWorkflowCreateOptions() : null,
            'texts' => $this->getWorkflowDesignTexts(),
        ];
    }

    /**
     * 新規作成のときに使う選択肢（種類・対象テーブル）。
     *
     * 中身はステップ1 の新規作成フォームとまったく同じものを使う。
     *
     * @return array
     */
    protected function getWorkflowCreateOptions(): array
    {
        $tables = CustomTable::allRecords(function ($custom_table) {
            return !in_array($custom_table->table_name, SystemTableName::SYSTEM_TABLE_NAME_MASTER())
                && !in_array($custom_table->table_name, SystemTableName::SYSTEM_TABLE_NAME_IGNORE_SAVED_AUTHORITY());
        })->pluck('table_view_name', 'id')->toArray();

        return [
            'workflow_type' => $this->toDesignOptionList(
                WorkflowType::transKeyArray('workflow.workflow_type_options')
            ),
            'custom_table' => $this->toDesignOptionList($tables),
            'table_value' => strval(WorkflowType::TABLE),
        ];
    }

    /**
     * データが入っているステータスの ID を返す。
     *
     * WorkflowStatus::deletingChildren() は、そのステータスを参照する workflow_values
     * （申請中の状態や承認履歴）も一緒に消す。ここに出てくるステータスは削除させない。
     *
     * @param array $status_ids
     * @return array<string> 使用中ステータスの ID（文字列）
     */
    protected function getUsedStatusIds(array $status_ids): array
    {
        $status_ids = array_values(array_filter($status_ids, function ($id) {
            return !is_nullorempty($id);
        }));
        if (count($status_ids) === 0) {
            return [];
        }

        $to = WorkflowValue::whereIn('workflow_status_to_id', $status_ids)
            ->distinct()->pluck('workflow_status_to_id')->all();
        $from = WorkflowValue::whereIn('workflow_status_from_id', $status_ids)
            ->distinct()->pluck('workflow_status_from_id')->all();

        return array_values(array_unique(array_map('strval', array_merge($to, $from))));
    }

    /**
     * ノードの座標。ステップ2のプレビューと同じ options.designer_layout を読む。
     *
     * @param Workflow $workflow
     * @return array
     */
    protected function getWorkflowDesignLayout(Workflow $workflow): array
    {
        $raw = $workflow->getOption('designer_layout');
        $layout = is_string($raw) ? json_decode($raw, true) : $raw;

        $pos = [];
        foreach ((array)array_get((array)$layout, 'pos', []) as $key => $value) {
            $x = array_get((array)$value, 'x');
            $y = array_get((array)$value, 'y');
            if (is_numeric($x) && is_numeric($y)) {
                $pos[strval($key)] = ['x' => floatval($x), 'y' => floatval($y)];
            }
        }

        return [
            'enabled' => boolval(array_get((array)$layout, 'enabled')),
            'pos' => $pos,
        ];
    }

    /**
     * 各種プルダウンの選択肢。
     *
     * @param Workflow $workflow
     * @param CustomTable|null $custom_table
     * @param array $selected 既に選ばれている ID（大量データの場合に名前を引くため）
     * @return array
     */
    protected function getWorkflowDesignOptions(Workflow $workflow, $custom_table, array $selected): array
    {
        $user_table = CustomTable::getEloquent(SystemTableName::USER);
        list($user_options, $user_ajax) = $user_table->getSelectOptionsAndAjaxUrl([
            'display_table' => $custom_table,
            'selected_value' => $selected[ConditionTypeDetail::USER()->lowerKey()],
        ]);

        $org_options = [];
        $org_ajax = null;
        $org_available = System::organization_available();
        if ($org_available) {
            list($org_options, $org_ajax) = CustomTable::getEloquent(SystemTableName::ORGANIZATION)
                ->getSelectOptionsAndAjaxUrl([
                    'display_table' => $custom_table,
                    'selected_value' => $selected[ConditionTypeDetail::ORGANIZATION()->lowerKey()],
                ]);
        }

        // 対象テーブルのユーザー列・組織列（テーブル専用ワークフローのみ）
        $column_options = [];
        if (isset($custom_table)) {
            $column_options = $custom_table->custom_columns()
                ->whereIn('column_type', [ColumnType::USER, ColumnType::ORGANIZATION])
                ->indexEnabled()
                ->pluck('column_view_name', 'id')
                ->toArray();
        }

        // ログインユーザー情報の列（実行ユーザー情報から取得）
        $login_user_column_options = $user_table->custom_columns()
            ->whereIn('column_type', [ColumnType::USER, ColumnType::ORGANIZATION])
            ->indexEnabled()
            ->pluck('column_view_name', 'id')
            ->toArray();

        $work_target_types = [
            WorkflowWorkTargetType::FIX,
            WorkflowWorkTargetType::ACTION_SELECT,
            WorkflowWorkTargetType::GET_BY_USERINFO,
        ];

        return [
            'user' => [
                'items' => $this->toDesignOptionList($user_options),
                'ajax' => $user_ajax,
            ],
            'organization' => [
                'available' => $org_available,
                'items' => $this->toDesignOptionList($org_options),
                'ajax' => $org_ajax,
            ],
            'column' => ['items' => $this->toDesignOptionList($column_options)],
            'system' => ['items' => $this->toDesignOptionList(WorkflowTargetSystem::transKeyArray('common'))],
            'login_user_column' => ['items' => $this->toDesignOptionList($login_user_column_options)],
            'work_target_type' => collect($work_target_types)->map(function ($type) {
                return [
                    'id' => strval($type),
                    'text' => exmtrans('workflow.work_target_type_options.' . $type),
                ];
            })->toArray(),
            'comment_type' => collect(WorkflowCommentType::transArray('workflow.comment_options'))
                ->filter(function ($text) {
                    return !is_nullorempty($text);
                })
                ->map(function ($text, $key) {
                    return ['id' => strval($key), 'text' => strval($text)];
                })->values()->toArray(),
        ];
    }

    /**
     * 条件ヘッダ（work_conditions）を画面用の「分岐」に変換する。
     * 絞り込み条件はここでは表示しないが、保存時にそのまま書き戻すために持ち回す。
     *
     * @param mixed $work_conditions
     * @return array
     */
    protected function toDesignDestinations($work_conditions): array
    {
        $result = [];

        foreach (collect($work_conditions)->toArray() as $header) {
            $header = (array)$header;
            $conditions = (array)array_get($header, 'workflow_conditions', []);

            $result[] = [
                'id' => strval(array_get($header, 'id')),
                'status_to' => strval(array_get($header, 'status_to')),
                'enabled' => array_boolval($header, 'enabled_flg'),
                'condition_count' => count($conditions),
                'condition_join' => array_get($header, 'condition_join'),
                'condition_reverse' => array_get($header, 'condition_reverse'),
                'workflow_conditions' => $conditions,
            ];
        }

        return $result;
    }

    /**
     * ['id' => 'text'] を [['id' => .., 'text' => ..]] に変換する。
     *
     * @param mixed $options
     * @return array
     */
    protected function toDesignOptionList($options): array
    {
        $result = [];
        foreach (collect($options)->toArray() as $key => $text) {
            $result[] = ['id' => strval($key), 'text' => strval($text)];
        }

        return $result;
    }

    /**
     * JavaScript へ渡す文言。
     * ロケールにキーが無くても画面が落ちないよう、英語を土台にして上書きする。
     *
     * @return array
     */
    protected function getWorkflowDesignTexts(): array
    {
        $texts = array_merge(
            (array)trans('exment::exment.workflow.designer', [], 'en'),
            (array)exmtrans('workflow.designer'),
            (array)trans('exment::exment.workflow.design', [], 'en'),
            (array)exmtrans('workflow.design')
        );

        // 既存のラベルは重複定義せず、ここで拾って渡す
        $labels = [
            'workflow_view_name' => exmtrans('workflow.workflow_view_name'),
            'start_status_name' => exmtrans('workflow.start_status_name'),
            'status_name' => exmtrans('workflow.status_name'),
            'datalock_flg' => exmtrans('workflow.datalock_flg'),
            'action_name' => exmtrans('workflow.action_name'),
            'setting_complete' => exmtrans('workflow.setting_complete'),
            'beginning' => exmtrans('workflow.beginning'),
            'workflow_type' => exmtrans('workflow.workflow_type'),
            'table' => exmtrans('custom_table.table'),
            'notify' => exmtrans('notify.header'),
            'status_from' => exmtrans('workflow.status_from'),
            'status_to' => exmtrans('workflow.status_to'),
            'work_targets' => exmtrans('workflow.work_targets'),
            'work_conditions' => exmtrans('workflow.work_conditions'),
            'flow_next_type' => exmtrans('workflow.flow_next_type'),
            'upper_user' => exmtrans('workflow.upper_user'),
            'all_user' => exmtrans('workflow.all_user'),
            'ignore_work' => exmtrans('workflow.ignore_work'),
            'condition' => exmtrans('workflow.condition'),
            'has_condition' => exmtrans('workflow.has_condition'),
            'comment' => exmtrans('common.comment'),
            'custom_column' => exmtrans('common.custom_column'),
            'system' => exmtrans('common.system'),
            'user' => exmtrans('menu.system_definitions.user'),
            'organization' => exmtrans('menu.system_definitions.organization'),
            'save' => trans('admin.save'),
            'cancel' => trans('admin.cancel'),
            'delete' => trans('admin.delete'),
            'setting' => trans('admin.setting'),
            'list' => trans('admin.list'),
            'available' => exmtrans('common.available'),
            'help_datalock' => exmtrans('workflow.help.datalock_flg'),
            'help_status_name' => exmtrans('workflow.help.status_name'),
            'help_flow_next_type' => exmtrans('workflow.help.flow_next_type'),
            'help_ignore_work' => exmtrans('workflow.help.ignore_work'),
            'msg_same_action' => exmtrans('workflow.message.same_action'),
            'msg_ignore_action_select' => exmtrans('workflow.message.ignore_work_and_action_select'),
        ];

        foreach ($labels as $key => $value) {
            // JavaScript 側は文字として出すので、lang に入っている <br /> 等は落とす
            $texts['label_' . $key] = trim(strip_tags(str_replace(
                ['<br />', '<br/>', '<br>'],
                ' ',
                strval($value)
            )));
        }

        // JavaScript 側は平らなキーしか引かないので、入れ子は "message.conflict" の形にする
        $flat = [];
        foreach ($texts as $key => $value) {
            if (!is_array($value)) {
                $flat[$key] = $value;
                continue;
            }
            foreach ($value as $sub => $subValue) {
                if (!is_array($subValue)) {
                    $flat[$key . '.' . $sub] = $subValue;
                }
            }
        }

        return $flat;
    }

    /* =====================================================================
     *  検査
     * ================================================================== */

    /**
     * 保存前の検査。ステップ1・2の検査と同じ条件を、図の形のデータに対して行う。
     *
     * @param Workflow $workflow
     * @param array $payload
     * @return array<string> エラーメッセージの一覧
     */
    protected function validateWorkflowDesign(Workflow $workflow, array $payload): array
    {
        $errors = [];
        $isTable = $workflow->workflow_type == WorkflowType::TABLE;
        $isNew = !$workflow->exists;

        $required = function ($label) {
            return trans('validation.required', ['attribute' => $label]);
        };
        $maxlen = function ($label, $max) {
            return trans('validation.max.string', ['attribute' => $label, 'max' => $max]);
        };

        // ---- 新規作成のときだけ要る項目 ----
        if ($isNew) {
            $type = strval(array_get($payload, 'workflow_type', ''));
            if (!in_array($type, [WorkflowType::COMMON, WorkflowType::TABLE], true)) {
                $errors[] = $required(exmtrans('workflow.workflow_type'));
            } elseif ($type == WorkflowType::TABLE && is_nullorempty(array_get($payload, 'custom_table_id'))) {
                $errors[] = $required(exmtrans('custom_table.table'));
            }
        }

        // ---- ワークフロー本体 ----
        $view_name = trim(strval(array_get($payload, 'workflow_view_name')));
        if ($view_name === '') {
            $errors[] = $required(exmtrans('workflow.workflow_view_name'));
        } elseif (mb_strlen($view_name) > 40) {
            $errors[] = $maxlen(exmtrans('workflow.workflow_view_name'), 40);
        }

        $start_name = trim(strval(array_get($payload, 'start_status_name')));
        if ($start_name === '') {
            $errors[] = $required(exmtrans('workflow.start_status_name'));
        } elseif (mb_strlen($start_name) > 30) {
            $errors[] = $maxlen(exmtrans('workflow.start_status_name'), 30);
        }

        // ---- ステータス ----
        $statuses = array_get($payload, 'statuses', []);
        if (count($statuses) === 0) {
            $errors[] = $required(exmtrans('workflow.workflow_statuses'));
        }

        $status_keys = [Define::WORKFLOW_START_KEYNAME];
        $completed_count = 0;
        foreach ($statuses as $status) {
            $name = trim(strval(array_get($status, 'name')));
            if ($name === '') {
                $errors[] = $required(exmtrans('workflow.status_name'));
            } elseif (mb_strlen($name) > 30) {
                $errors[] = $maxlen(exmtrans('workflow.status_name') . '「' . $name . '」', 30);
            }

            if (array_boolval($status, 'completed')) {
                $completed_count++;
            }

            $key = strval(array_get($status, 'key') ?: array_get($status, 'id'));
            if ($key !== '') {
                $status_keys[] = $key;
            }
        }

        if (count($statuses) > 0 && $completed_count < 1) {
            $errors[] = exmtrans('workflow.design.message.completed_least');
        }

        // データが入っているステータスを消すと、そのデータの状態と履歴まで消える。
        // 画面側でも削除させないが、直接叩かれた場合に備えてここでも止める。
        $posted_ids = collect($statuses)->pluck('id')->filter()->map(function ($v) {
            return strval($v);
        })->values()->all();

        $removing = ($isNew ? collect() : $workflow->workflow_statuses()->get())
            ->filter(function ($status) use ($posted_ids) {
                return !in_array(strval($status->id), $posted_ids, true);
            });

        if ($removing->count() > 0) {
            $in_use_ids = $this->getUsedStatusIds($removing->pluck('id')->all());
            foreach ($removing as $status) {
                if (in_array(strval($status->id), $in_use_ids, true)) {
                    $errors[] = exmtrans('workflow.design.message.status_in_use', ['name' => $status->status_name]);
                }
            }
        }

        // ---- アクション ----
        $actions = array_get($payload, 'actions', []);
        if (count($actions) === 0) {
            $errors[] = $required(exmtrans('workflow.workflow_actions'));
        }

        $name_of = function ($status_key) use ($statuses, $start_name) {
            if ($status_key === Define::WORKFLOW_START_KEYNAME) {
                return $start_name;
            }
            foreach ($statuses as $status) {
                $key = strval(array_get($status, 'key') ?: array_get($status, 'id'));
                if ($key === $status_key) {
                    return strval(array_get($status, 'name'));
                }
            }
            return $status_key;
        };

        $has_start_action = false;

        foreach ($actions as $index => $action) {
            $action_name = trim(strval(array_get($action, 'name')));
            $label = $action_name !== '' ? '「' . $action_name . '」' : '#' . ($index + 1);

            if ($action_name === '') {
                $errors[] = $required(exmtrans('workflow.action_name')) . ' (' . $label . ')';
            } elseif (mb_strlen($action_name) > 30) {
                $errors[] = $maxlen(exmtrans('workflow.action_name') . $label, 30);
            }

            $status_from = strval(array_get($action, 'status_from'));
            if ($status_from === '') {
                $errors[] = $required(exmtrans('workflow.status_from')) . ' (' . $label . ')';
            } elseif (!in_array($status_from, $status_keys, true)) {
                $errors[] = exmtrans('workflow.design.message.unknown_status', ['action' => $label]);
            }

            if ($status_from === Define::WORKFLOW_START_KEYNAME) {
                $has_start_action = true;
            }

            // 実行後ステータス（分岐）
            $destinations = collect(array_get($action, 'destinations', []))
                ->filter(function ($destination) {
                    return array_boolval($destination, 'enabled')
                        && !is_nullorempty(array_get($destination, 'status_to'));
                })->values();

            if ($destinations->count() === 0) {
                $errors[] = $required(exmtrans('workflow.status_to')) . ' (' . $label . ')';
            }

            foreach ($destinations as $destination) {
                $status_to = strval(array_get($destination, 'status_to'));
                if (!in_array($status_to, $status_keys, true)) {
                    $errors[] = exmtrans('workflow.design.message.unknown_status', ['action' => $label]);
                    continue;
                }
                if ($status_to === $status_from) {
                    $errors[] = exmtrans('workflow.message.same_action') . ' (' . $label . ')';
                }
            }

            // 実行可能ユーザー
            $work_target_type = strval(array_get($action, 'work_target_type'));
            if (!in_array($work_target_type, [
                WorkflowWorkTargetType::FIX,
                WorkflowWorkTargetType::ACTION_SELECT,
                WorkflowWorkTargetType::GET_BY_USERINFO,
            ], true)) {
                $errors[] = $required(exmtrans('workflow.work_targets')) . ' (' . $label . ')';
            } elseif ($work_target_type != WorkflowWorkTargetType::ACTION_SELECT) {
                $has_target = false;
                foreach (static::designTargetKeys() as $key) {
                    if (count((array)array_get($action, "targets.$key", [])) > 0) {
                        $has_target = true;
                        break;
                    }
                }
                if (!$has_target) {
                    $errors[] = $required(exmtrans('workflow.work_targets')) . ' (' . $label . ')';
                }
            }

            // 「前アクションの実行ユーザーが選択」と「特殊なアクション」は併用できない
            if ($work_target_type == WorkflowWorkTargetType::ACTION_SELECT && array_boolval($action, 'ignore_work')) {
                $errors[] = exmtrans('workflow.message.ignore_work_and_action_select') . ' (' . $label . ')';
            }

            // 同じ実行前ステータスで「前アクションの実行ユーザーが選択」と他の設定を混ぜられない
            if ($work_target_type == WorkflowWorkTargetType::ACTION_SELECT) {
                foreach ($actions as $other_index => $other) {
                    if ($index == $other_index) {
                        continue;
                    }
                    if (strval(array_get($other, 'status_from')) !== $status_from) {
                        continue;
                    }
                    if (array_boolval($other, 'ignore_work')) {
                        continue;
                    }

                    $other_type = strval(array_get($other, 'work_target_type'));
                    if ($other_type === $work_target_type) {
                        continue;
                    }

                    $errors[] = exmtrans('workflow.message.' . $other_type . '_and_action_select')
                        . ' (' . $name_of($status_from) . ')';
                    break;
                }
            }

            // オプション
            if (!in_array(strval(array_get($action, 'flow_next_type')), [WorkflowNextType::SOME, WorkflowNextType::ALL], true)) {
                $errors[] = $required(exmtrans('workflow.flow_next_type')) . ' (' . $label . ')';
            }

            $flow_next_count = array_get($action, 'flow_next_count');
            if (!is_numeric($flow_next_count) || $flow_next_count < 0 || $flow_next_count > 10) {
                $errors[] = exmtrans('workflow.designer.issue_flow_next_count', ['action' => $action_name]);
            }

            if (!in_array(strval(array_get($action, 'comment_type')), [
                WorkflowCommentType::REQUIRED,
                WorkflowCommentType::NULLABLE,
                WorkflowCommentType::NOTUSE,
            ], true)) {
                $errors[] = $required(exmtrans('common.comment')) . ' (' . $label . ')';
            }
        }

        if (count($actions) > 0 && !$has_start_action) {
            $errors[] = exmtrans('workflow.designer.issue_no_start', ['status' => $start_name]);
        }

        return array_values(array_unique($errors));
    }

    /* =====================================================================
     *  保存
     * ================================================================== */

    /**
     * ステータスを保存する。
     *
     * Exment はステップ1で「order が最後の行＝完了ステータス」として completed_flg を
     * 付け直す。デザイナーもその並びに合わせて order を振り直すので、後からステップ1で
     * 保存し直しても完了ステータスがずれない。
     *
     * @param Workflow $workflow
     * @param array $posted
     * @return array<string,string> 仮キー（new:xxx）→ 実 ID
     */
    protected function saveDesignStatuses(Workflow $workflow, array $posted): array
    {
        $map = [];

        // 完了ステータスを最後に回す。それ以外は画面上の並びのまま。
        $ordered = collect($posted)->sortBy(function ($status) {
            return array_boolval($status, 'completed') ? 1 : 0;
        })->values();

        $keep_ids = [];
        $order = 1;

        foreach ($ordered as $item) {
            $id = array_get($item, 'id');
            $status = null;

            if (!is_nullorempty($id)) {
                $status = WorkflowStatus::where('workflow_id', $workflow->id)->where('id', $id)->first();
            }
            if (!isset($status)) {
                $status = new WorkflowStatus();
                $status->workflow_id = $workflow->id;
                $status->status_type = 0;
            }

            $status->status_name = trim(strval(array_get($item, 'name')));
            $status->datalock_flg = array_boolval($item, 'datalock') ? 1 : 0;
            $status->completed_flg = array_boolval($item, 'completed') ? 1 : 0;
            $status->order = $order++;
            $status->save();

            $keep_ids[] = $status->id;

            $key = strval(array_get($item, 'key') ?: array_get($item, 'id'));
            if ($key !== '') {
                $map[$key] = strval($status->id);
            }
        }

        // 画面から消えたステータスを削除する。
        // deletingChildren() が、そのステータスを参照する workflow_values も片付ける。
        $removed = WorkflowStatus::where('workflow_id', $workflow->id)
            ->whereNotIn('id', count($keep_ids) > 0 ? $keep_ids : [0])
            ->get();
        foreach ($removed as $status) {
            $status->deletingChildren();
            $status->delete();
        }

        return $map;
    }

    /**
     * アクションを保存する。
     *
     * @param Workflow $workflow
     * @param array $posted
     * @param array<string,string> $status_map
     * @return array<string,string> 仮キー（new:xxx）→ 実 ID
     */
    protected function saveDesignActions(Workflow $workflow, array $posted, array $status_map): array
    {
        $map = [];

        $resolve = function ($key) use ($status_map) {
            $key = strval($key);
            if ($key === Define::WORKFLOW_START_KEYNAME || $key === '') {
                return $key;
            }
            return array_get($status_map, $key, $key);
        };

        // ステップ2は「1行目＝開始からのアクション」を前提に画面を組み立てる。
        // 行の並びは id 順なので、新規作成するときは開始アクションを先に採番する。
        $ordered = collect($posted)->sortBy(function ($action) {
            $is_new = is_nullorempty(array_get($action, 'id'));
            $is_start = strval(array_get($action, 'status_from')) === Define::WORKFLOW_START_KEYNAME;
            return ($is_new ? 1 : 0) * 10 + ($is_start ? 0 : 1);
        })->values();

        $keep_ids = [];

        foreach ($ordered as $item) {
            $id = array_get($item, 'id');
            $action = null;

            if (!is_nullorempty($id)) {
                $action = WorkflowAction::where('workflow_id', $workflow->id)->where('id', $id)->first();
            }
            if (!isset($action)) {
                $action = new WorkflowAction();
                $action->workflow_id = $workflow->id;
            }

            $action->action_name = trim(strval(array_get($item, 'name')));
            $action->status_from = $resolve(array_get($item, 'status_from'));
            $action->ignore_work = array_boolval($item, 'ignore_work') ? 1 : 0;
            $action->flow_next_type = strval(array_get($item, 'flow_next_type'));
            $action->flow_next_count = intval(array_get($item, 'flow_next_count'));
            $action->comment_type = strval(array_get($item, 'comment_type'));

            // 実行可能ユーザー。保存後の setActionAuthority() が
            // workflow_authorities へ反映する。
            // 種別を切り替えても選んだ相手は消さない（ステップ2のモーダルと同じ動き）。
            $work_targets = ['work_target_type' => strval(array_get($item, 'work_target_type'))];
            foreach (static::designTargetKeys() as $key) {
                $values = array_values(array_filter((array)array_get($item, "targets.$key", []), function ($v) {
                    return !is_nullorempty($v);
                }));
                if (count($values) > 0) {
                    $work_targets[$key] = $values;
                }
            }
            $action->work_targets = $work_targets;

            // 実行後ステータス。絞り込み条件（workflow_conditions）は画面では触らず、
            // 送られてきたものをそのまま書き戻すので消えない。
            $action->work_conditions = $this->buildDesignConditions($item, $resolve);

            $action->save();

            $keep_ids[] = $action->id;

            $key = strval(array_get($item, 'key') ?: array_get($item, 'id'));
            if ($key !== '') {
                $map[$key] = strval($action->id);
            }
        }

        // 画面から消えたアクションを削除する（論理削除。子の権限・条件は消える）
        $removed = WorkflowAction::where('workflow_id', $workflow->id)
            ->whereNotIn('id', count($keep_ids) > 0 ? $keep_ids : [0])
            ->get();
        foreach ($removed as $action) {
            $action->delete();
        }

        return $map;
    }

    /**
     * 1つのアクションの実行後ステータス（条件ヘッダ）を組み立てる。
     *
     * @param array $item
     * @param callable $resolve 仮キー→実 ID
     * @return array
     */
    protected function buildDesignConditions(array $item, callable $resolve): array
    {
        $result = [];

        foreach ((array)array_get($item, 'destinations', []) as $destination) {
            $status_to = strval(array_get($destination, 'status_to'));
            if ($status_to === '') {
                continue;
            }
            if (!array_boolval($destination, 'enabled')) {
                continue;
            }

            $header = [
                'status_to' => $resolve($status_to),
                'enabled_flg' => 1,
            ];

            // ID を引き継ぐとヘッダの採番が変わらない（ステップ2の保存と同じ動き）
            $header_id = array_get($destination, 'id');
            if (is_numeric($header_id)) {
                $header['id'] = intval($header_id);
            }

            // 既存ヘッダなら絞り込み条件をそのまま引き継ぐ
            $conditions = array_get($destination, 'workflow_conditions');
            if (!is_nullorempty($conditions)) {
                $header['workflow_conditions'] = collect($conditions)->map(function ($condition) {
                    return array_only((array)$condition, [
                        'id', 'condition_target', 'condition_type', 'condition_key', 'condition_value',
                    ]);
                })->values()->toArray();
            }

            foreach (['condition_join', 'condition_reverse'] as $key) {
                $value = array_get($destination, $key);
                if (!is_nullorempty($value)) {
                    $header[$key] = $value;
                }
            }

            $result[] = $header;
        }

        return $result;
    }

    /**
     * ノードの座標を options.designer_layout へ保存する。
     * ステップ2のプレビューが読むキーと同じなので、両方の画面で並びが一致する。
     *
     * @param Workflow $workflow
     * @param array $layout
     * @param array<string,string> $status_map
     * @return void
     */
    protected function saveDesignLayout(Workflow $workflow, $layout, array $status_map): void
    {
        $layout = (array)$layout;

        $pos = [];
        foreach ((array)array_get($layout, 'pos', []) as $key => $value) {
            $x = array_get((array)$value, 'x');
            $y = array_get((array)$value, 'y');
            if (!is_numeric($x) || !is_numeric($y)) {
                continue;
            }

            // 新規ステータスの仮キーは、採番された実 ID に置き換える
            $key = strval($key);
            $key = array_get($status_map, $key, $key);

            $pos[$key] = ['x' => round(floatval($x), 1), 'y' => round(floatval($y), 1)];
        }

        $workflow->setOption('designer_layout', json_encode([
            'enabled' => boolval(array_get($layout, 'enabled', true)),
            'pos' => $pos,
        ], JSON_UNESCAPED_UNICODE));

        $workflow->save();
    }
}
