<?php

/**
 * ServiceNow -> Exment.
 *
 * The fields below are the ones on the out-of-the-box incident, change_request
 * and problem tables. Almost every real instance has been customised on top of
 * those, so treat this as the starting point it is: run with --dry first, read
 * the "not mapped" list it prints, and add what matters. Copy the file into
 * storage first (exment:migrate-in --publish=servicenow) so the edits survive
 * the next update.
 *
 * The source is fetched with sysparm_display_value=all, so every field arrives
 * as {"value": ..., "display_value": ...}:
 *
 *     "priority":    {"value": "1", "display_value": "1 - Critical"}
 *     "assigned_to": {"value": "<sys_id>", "display_value": "Abel Tuter"}
 *
 * So ".display_value" is what a person should see and ".value" is what links to
 * another record. A field named without either suffix collapses to the label,
 * which is also what a csv export of the same table gives you - the same preset
 * works on both.
 *
 * Conversations are not columns. They live in sys_journal_field, one row per
 * entry, and become the snow_journal table at the bottom of this file.
 */

return [
    'name' => 'servicenow',
    'label' => 'ServiceNow',

    // stored values are UTC and carry no marker; parsing them as local time
    // would put every timestamp in a Japanese instance nine hours out
    'source_timezone' => 'UTC',

    'key_label' => '移行キー',
    'view_label' => '全件ビュー',
    'raw_label' => '移行元データ',

    'streams' => [

        // pulled so assigned_to can be turned into a real person, not shown as
        // a table of its own
        'sys_user' => ['skip' => true],
        'sys_user_group' => ['skip' => true],

        'incident' => [
            'table' => 'snow_incident',
            'label_column' => 'number',
            'list' => ['number', 'short_description', 'state', 'priority', 'assigned_to', 'opened_at'],
            'label' => 'ServiceNow インシデント',
            'key' => 'sys_id.value',
            'columns' => [
                'number' => ['label' => '番号', 'type' => 'text', 'from' => 'number.display_value', 'index' => true],
                'short_description' => ['label' => '概要', 'type' => 'text', 'from' => 'short_description.display_value', 'index' => true],
                'description' => ['label' => '詳細', 'type' => 'textarea', 'from' => 'description.display_value'],

                'state' => [
                    'label' => '状態',
                    'type' => 'select_valtext',
                    'from' => 'state.display_value',
                    'index' => true,
                ],
                'priority' => ['label' => '優先度', 'type' => 'select_valtext', 'from' => 'priority.display_value'],
                'urgency' => ['label' => '緊急度', 'type' => 'select_valtext', 'from' => 'urgency.display_value'],
                'impact' => ['label' => '影響度', 'type' => 'select_valtext', 'from' => 'impact.display_value'],
                'category' => ['label' => 'カテゴリー', 'type' => 'select_valtext', 'from' => 'category.display_value'],
                'subcategory' => ['label' => 'サブカテゴリー', 'type' => 'text', 'from' => 'subcategory.display_value'],
                'contact_type' => ['label' => '受付経路', 'type' => 'select_valtext', 'from' => 'contact_type.display_value'],

                // the sys_id is translated through the staged sys_user dump to
                // an address, and the address to an Exment user
                'assigned_to' => [
                    'label' => '担当者',
                    'type' => 'user',
                    'from' => 'assigned_to.value',
                    'via' => ['stream' => 'sys_user', 'key' => 'sys_id.value', 'value' => 'email.value'],
                ],
                'assigned_to_name' => ['label' => '担当者（原文）', 'type' => 'text', 'from' => 'assigned_to.display_value'],
                'caller' => [
                    'label' => '申告者',
                    'type' => 'user',
                    'from' => 'caller_id.value',
                    'via' => ['stream' => 'sys_user', 'key' => 'sys_id.value', 'value' => 'email.value'],
                ],
                'caller_name' => ['label' => '申告者（原文）', 'type' => 'text', 'from' => 'caller_id.display_value'],
                'assignment_group' => ['label' => '担当グループ', 'type' => 'text', 'from' => 'assignment_group.display_value'],

                'opened_at' => ['label' => 'オープン日時', 'type' => 'datetime', 'from' => 'opened_at.value', 'index' => true],
                'resolved_at' => ['label' => '解決日時', 'type' => 'datetime', 'from' => 'resolved_at.value'],
                'closed_at' => ['label' => 'クローズ日時', 'type' => 'datetime', 'from' => 'closed_at.value'],
                'close_code' => ['label' => '解決コード', 'type' => 'text', 'from' => 'close_code.display_value'],
                'close_notes' => ['label' => '解決内容', 'type' => 'textarea', 'from' => 'close_notes.display_value'],

                'source_created' => ['label' => '作成日時（原）', 'type' => 'datetime', 'from' => 'sys_created_on.value'],
                'source_updated' => ['label' => '更新日時（原）', 'type' => 'datetime', 'from' => 'sys_updated_on.value'],
            ],
        ],

        'change_request' => [
            'table' => 'snow_change_request',
            'label_column' => 'number',
            'list' => ['number', 'short_description', 'state', 'change_type', 'assigned_to', 'planned_start'],
            'label' => 'ServiceNow 変更要求',
            'key' => 'sys_id.value',
            'columns' => [
                'number' => ['label' => '番号', 'type' => 'text', 'from' => 'number.display_value', 'index' => true],
                'short_description' => ['label' => '概要', 'type' => 'text', 'from' => 'short_description.display_value', 'index' => true],
                'description' => ['label' => '詳細', 'type' => 'textarea', 'from' => 'description.display_value'],

                'state' => ['label' => '状態', 'type' => 'select_valtext', 'from' => 'state.display_value', 'index' => true],
                'change_type' => ['label' => '変更種別', 'type' => 'select_valtext', 'from' => 'type.display_value'],
                'risk' => ['label' => 'リスク', 'type' => 'select_valtext', 'from' => 'risk.display_value'],
                'impact' => ['label' => '影響度', 'type' => 'select_valtext', 'from' => 'impact.display_value'],
                'priority' => ['label' => '優先度', 'type' => 'select_valtext', 'from' => 'priority.display_value'],
                'category' => ['label' => 'カテゴリー', 'type' => 'select_valtext', 'from' => 'category.display_value'],

                'requested_by' => [
                    'label' => '申請者',
                    'type' => 'user',
                    'from' => 'requested_by.value',
                    'via' => ['stream' => 'sys_user', 'key' => 'sys_id.value', 'value' => 'email.value'],
                ],
                'requested_by_name' => ['label' => '申請者（原文）', 'type' => 'text', 'from' => 'requested_by.display_value'],
                'assigned_to' => [
                    'label' => '担当者',
                    'type' => 'user',
                    'from' => 'assigned_to.value',
                    'via' => ['stream' => 'sys_user', 'key' => 'sys_id.value', 'value' => 'email.value'],
                ],
                'assigned_to_name' => ['label' => '担当者（原文）', 'type' => 'text', 'from' => 'assigned_to.display_value'],
                'assignment_group' => ['label' => '担当グループ', 'type' => 'text', 'from' => 'assignment_group.display_value'],

                'planned_start' => ['label' => '開始予定', 'type' => 'datetime', 'from' => 'start_date.value'],
                'planned_end' => ['label' => '終了予定', 'type' => 'datetime', 'from' => 'end_date.value'],
                'justification' => ['label' => '実施理由', 'type' => 'textarea', 'from' => 'justification.display_value'],
                'implementation_plan' => ['label' => '実施計画', 'type' => 'textarea', 'from' => 'implementation_plan.display_value'],
                'backout_plan' => ['label' => '切り戻し計画', 'type' => 'textarea', 'from' => 'backout_plan.display_value'],

                'source_created' => ['label' => '作成日時（原）', 'type' => 'datetime', 'from' => 'sys_created_on.value'],
                'source_updated' => ['label' => '更新日時（原）', 'type' => 'datetime', 'from' => 'sys_updated_on.value'],
            ],
        ],

        'problem' => [
            'table' => 'snow_problem',
            'label_column' => 'number',
            'list' => ['number', 'short_description', 'state', 'priority', 'assigned_to'],
            'label' => 'ServiceNow 問題',
            'key' => 'sys_id.value',
            'columns' => [
                'number' => ['label' => '番号', 'type' => 'text', 'from' => 'number.display_value', 'index' => true],
                'short_description' => ['label' => '概要', 'type' => 'text', 'from' => 'short_description.display_value', 'index' => true],
                'description' => ['label' => '詳細', 'type' => 'textarea', 'from' => 'description.display_value'],

                'state' => ['label' => '状態', 'type' => 'select_valtext', 'from' => 'state.display_value', 'index' => true],
                'priority' => ['label' => '優先度', 'type' => 'select_valtext', 'from' => 'priority.display_value'],
                'impact' => ['label' => '影響度', 'type' => 'select_valtext', 'from' => 'impact.display_value'],
                'urgency' => ['label' => '緊急度', 'type' => 'select_valtext', 'from' => 'urgency.display_value'],

                'assigned_to' => [
                    'label' => '担当者',
                    'type' => 'user',
                    'from' => 'assigned_to.value',
                    'via' => ['stream' => 'sys_user', 'key' => 'sys_id.value', 'value' => 'email.value'],
                ],
                'assigned_to_name' => ['label' => '担当者（原文）', 'type' => 'text', 'from' => 'assigned_to.display_value'],
                'assignment_group' => ['label' => '担当グループ', 'type' => 'text', 'from' => 'assignment_group.display_value'],

                'cause_notes' => ['label' => '原因', 'type' => 'textarea', 'from' => 'cause_notes.display_value'],
                'fix_notes' => ['label' => '恒久対策', 'type' => 'textarea', 'from' => 'fix_notes.display_value'],

                'source_created' => ['label' => '作成日時（原）', 'type' => 'datetime', 'from' => 'sys_created_on.value'],
                'source_updated' => ['label' => '更新日時（原）', 'type' => 'datetime', 'from' => 'sys_updated_on.value'],
            ],
        ],

        'journal' => [
            'table' => 'snow_journal',
            'label_column' => 'body',
            'list' => ['parent_table', 'entry_type', 'incident', 'author', 'posted_at'],
            'label' => 'ServiceNow 作業ログ・コメント',
            'key' => 'sys_id.value',
            'columns' => [
                'parent_table' => ['label' => '対象テーブル', 'type' => 'text', 'from' => 'name.display_value', 'index' => true],
                'entry_type' => ['label' => '種別', 'type' => 'select_valtext', 'from' => 'element.display_value'],
                'body' => ['label' => '内容', 'type' => 'textarea', 'from' => 'value'],

                'author' => [
                    'label' => '記入者',
                    'type' => 'user',
                    'from' => 'sys_created_by.display_value',
                    'via' => ['stream' => 'sys_user', 'key' => 'user_name.value', 'value' => 'email.value'],
                ],
                'author_name' => ['label' => '記入者（原文）', 'type' => 'text', 'from' => 'sys_created_by.display_value'],
                'posted_at' => ['label' => '記入日時', 'type' => 'datetime', 'from' => 'sys_created_on.value', 'index' => true],

                // one journal table holds the conversation of every record
                // table, so each link only applies to its own rows
                'incident' => [
                    'label' => 'インシデント',
                    'type' => 'select_table',
                    'from' => 'element_id.value',
                    'ref' => ['stream' => 'incident', 'when' => ['name.display_value' => 'incident']],
                ],
                'change_request' => [
                    'label' => '変更要求',
                    'type' => 'select_table',
                    'from' => 'element_id.value',
                    'ref' => ['stream' => 'change_request', 'when' => ['name.display_value' => 'change_request']],
                ],
                'problem' => [
                    'label' => '問題',
                    'type' => 'select_table',
                    'from' => 'element_id.value',
                    'ref' => ['stream' => 'problem', 'when' => ['name.display_value' => 'problem']],
                ],

                // kept so an entry belonging to a table nobody migrated is
                // still traceable rather than orphaned
                'parent_sys_id' => ['label' => '対象レコード ID', 'type' => 'text', 'from' => 'element_id.value', 'index' => true],
            ],
        ],

        'attachment' => [
            'table' => 'snow_attachment',
            'label_column' => 'file_name',
            'list' => ['file_name', 'parent_table', 'content_type', 'size_bytes', 'source_created'],
            'label' => 'ServiceNow 添付ファイル（一覧）',
            'key' => 'sys_id',
            'columns' => [
                'file_name' => ['label' => 'ファイル名', 'type' => 'text', 'from' => 'file_name', 'index' => true],
                'parent_table' => ['label' => '対象テーブル', 'type' => 'text', 'from' => 'table_name', 'index' => true],
                'parent_sys_id' => ['label' => '対象レコード ID', 'type' => 'text', 'from' => 'table_sys_id', 'index' => true],
                'content_type' => ['label' => '種別', 'type' => 'text', 'from' => 'content_type'],
                'size_bytes' => ['label' => 'サイズ', 'type' => 'integer', 'from' => 'size_bytes'],
                'download_link' => [
                    'label' => 'ダウンロード URL',
                    'type' => 'url',
                    'from' => 'download_link',
                    'help' => 'ServiceNow 上の URL です。ファイル本体は移行していません。',
                ],
                'source_created' => ['label' => '作成日時（原）', 'type' => 'datetime', 'from' => 'sys_created_on'],

                'incident' => [
                    'label' => 'インシデント',
                    'type' => 'select_table',
                    'from' => 'table_sys_id',
                    'ref' => ['stream' => 'incident', 'when' => ['table_name' => 'incident']],
                ],
            ],
        ],
    ],
];
