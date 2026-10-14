<?php

/**
 * Backlog -> Exment.
 *
 * Field names are the ones the Backlog v2 API actually sends. An issue arrives
 * with its issueType, status, priority, assignee, category, versions and
 * milestone already nested inside it, so most of this is reading one level
 * down rather than joining anything.
 *
 * Two habits run through the whole file and are worth understanding before
 * changing it:
 *
 *   Keys are numeric ids, not issue keys. "DEMO-42" is friendlier, but
 *   parentIssueId and issueId - the two fields that link records together -
 *   are numeric, and a link only works when both ends agree. The readable key
 *   is kept as an ordinary column.
 *
 *   Every person is stored twice: once as an Exment user, once as plain text.
 *   The user column is empty whenever nobody in Exment has that address, which
 *   on a real migration means everyone who has since left. The text column is
 *   what stops "who reported this" from becoming unanswerable.
 */

return [
    'name' => 'backlog',
    'label' => 'Backlog',

    // Backlog stamps its timestamps with a Z, so this only matters for the
    // date-only fields, which have no zone at all
    'source_timezone' => 'UTC',

    'key_label' => '移行キー',
    'view_label' => '全件ビュー',
    'raw_label' => '移行元データ',

    'streams' => [

        // masters. They become the option lists on the issue table rather than
        // tables of their own - a table of four priorities helps nobody
        'user' => ['skip' => true],
        'priority' => ['skip' => true],
        'status' => ['skip' => true],
        'issue_type' => ['skip' => true],
        'category' => ['skip' => true],
        'version' => ['skip' => true],

        'project' => [
            'table' => 'backlog_project',
            'label_column' => 'project_key',
            'list' => ['project_key', 'project_name', 'archived'],
            'label' => 'Backlog プロジェクト',
            'key' => 'id',
            'columns' => [
                'project_key' => ['label' => 'プロジェクトキー', 'type' => 'text', 'from' => 'projectKey', 'index' => true],
                'project_name' => ['label' => 'プロジェクト名', 'type' => 'text', 'from' => 'name', 'index' => true],
                'archived' => ['label' => 'アーカイブ済み', 'type' => 'yesno', 'from' => 'archived'],
            ],
        ],

        'issue' => [
            'table' => 'backlog_issue',
            'label_column' => 'issue_key',
            'list' => ['issue_key', 'summary', 'issue_type', 'status', 'priority', 'assignee', 'due_date'],
            'label' => 'Backlog 課題',
            'key' => 'id',
            'columns' => [
                'issue_key' => ['label' => '課題キー', 'type' => 'text', 'from' => 'issueKey', 'index' => true],
                'summary' => ['label' => '件名', 'type' => 'text', 'from' => 'summary', 'index' => true],
                'description' => ['label' => '詳細', 'type' => 'textarea', 'from' => 'description'],

                'issue_type' => [
                    'label' => '種別',
                    'type' => 'select_valtext',
                    'from' => 'issueType.name',
                    'choices_from' => ['stream' => 'issue_type', 'value' => 'name', 'label' => 'name'],
                ],
                'status' => [
                    'label' => '状態',
                    'type' => 'select_valtext',
                    'from' => 'status.name',
                    // taken from the project's own status list, so a status no
                    // ticket currently sits in is still an option afterwards
                    'choices_from' => ['stream' => 'status', 'value' => 'name', 'label' => 'name'],
                    'index' => true,
                ],
                'priority' => [
                    'label' => '優先度',
                    'type' => 'select_valtext',
                    'from' => 'priority.name',
                    'choices_from' => ['stream' => 'priority', 'value' => 'name', 'label' => 'name'],
                ],
                'resolution' => ['label' => '完了理由', 'type' => 'text', 'from' => 'resolution.name'],

                'assignee' => ['label' => '担当者', 'type' => 'user', 'from' => 'assignee.mailAddress'],
                'assignee_name' => ['label' => '担当者（原文）', 'type' => 'text', 'from' => 'assignee.name'],
                'reporter' => ['label' => '登録者', 'type' => 'user', 'from' => 'createdUser.mailAddress'],
                'reporter_name' => ['label' => '登録者（原文）', 'type' => 'text', 'from' => 'createdUser.name'],

                // these arrive as lists of objects; kept as readable text
                // because turning each into its own table buys nothing once
                // the project is closed
                'category' => ['label' => 'カテゴリー', 'type' => 'text', 'from' => 'category', 'transform' => 'names'],
                'milestone' => ['label' => 'マイルストーン', 'type' => 'text', 'from' => 'milestone', 'transform' => 'names'],
                'versions' => ['label' => '発生バージョン', 'type' => 'text', 'from' => 'versions', 'transform' => 'names'],

                'start_date' => ['label' => '開始日', 'type' => 'date', 'from' => 'startDate'],
                'due_date' => ['label' => '期限日', 'type' => 'date', 'from' => 'dueDate'],
                'estimated_hours' => ['label' => '予定時間', 'type' => 'decimal', 'from' => 'estimatedHours'],
                'actual_hours' => ['label' => '実績時間', 'type' => 'decimal', 'from' => 'actualHours'],

                'opened_at' => ['label' => '登録日時', 'type' => 'datetime', 'from' => 'created', 'index' => true],
                'last_updated' => ['label' => '更新日時', 'type' => 'datetime', 'from' => 'updated'],

                'project' => [
                    'label' => 'プロジェクト',
                    'type' => 'select_table',
                    'from' => 'projectId',
                    'ref' => ['stream' => 'project'],
                ],
                'parent_issue' => [
                    'label' => '親課題',
                    'type' => 'select_table',
                    'from' => 'parentIssueId',
                    // points back at this same table; a child filed before its
                    // parent is patched on the second pass
                    'ref' => ['stream' => 'issue'],
                ],

                'attachment_names' => [
                    'label' => '添付ファイル（名称）',
                    'type' => 'textarea',
                    'from' => 'attachments',
                    'transform' => 'names',
                    'help' => 'ファイル名のみ。実体は移行していません。',
                ],
            ],
        ],

        'comment' => [
            'table' => 'backlog_comment',
            'label_column' => 'body',
            'list' => ['issue', 'author', 'body', 'posted_at'],
            'label' => 'Backlog コメント',
            'key' => 'id',
            'columns' => [
                'issue' => [
                    'label' => '課題',
                    'type' => 'select_table',
                    'from' => 'issueId',
                    'ref' => ['stream' => 'issue'],
                ],
                'body' => ['label' => '内容', 'type' => 'textarea', 'from' => 'content'],
                'author' => ['label' => '投稿者', 'type' => 'user', 'from' => 'createdUser.mailAddress'],
                'author_name' => ['label' => '投稿者（原文）', 'type' => 'text', 'from' => 'createdUser.name'],
                'posted_at' => ['label' => '投稿日時', 'type' => 'datetime', 'from' => 'created', 'index' => true],
            ],
        ],
    ],
];
