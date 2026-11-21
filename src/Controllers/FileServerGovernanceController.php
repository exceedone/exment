<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Model\FileAuditLog;
use Exceedone\Exment\Model\FileComment;
use Exceedone\Exment\Model\FileNode;
use Exceedone\Exment\Model\FileWorkspace;
use Exceedone\Exment\Services\FileServer\Audit;
use Exceedone\Exment\Services\FileServer\Collaboration;
use Exceedone\Exment\Services\FileServer\FileServerException;
use Exceedone\Exment\Services\FileServer\Governance;
use Exceedone\Exment\Services\FileServer\Level;
use ExmentAdminCore\Admin\Layout\Content;
use Illuminate\Http\Request;

/**
 * File server, phase 5 (ISS00379): the audit log, the approvals, the rules
 * of the folders and files (legal hold, approval), the follows and the comments.
 */
class FileServerGovernanceController extends FileServerController
{
    public const AUDIT_PAGE_SIZE = 100;
    public const AUDIT_CSV_MAX = 100000;

    // ================================================================ the audit log

    /**
     * The log of a workspace (those who manage it), or its CSV (?format=csv).
     *
     * @return Content|\Symfony\Component\HttpFoundation\Response
     */
    public function audit(Request $request, Content $content, string $workspace)
    {
        try {
            $ws = $this->workspace($workspace);
            $this->library()->access()->authorize($ws, null, Level::MANAGE);
        } catch (FileServerException $e) {
            return $this->deny($e);
        }
        $filters = [
            'action' => strval($request->get('action', '')),
            'user_id' => strval($request->get('user_id', '')),
            'node' => mb_substr(strval($request->get('node', '')), 0, 100),
            'from' => strval($request->get('from', '')),
            'to' => strval($request->get('to', '')),
        ];
        $query = Audit::query($ws, $filters);

        if ($request->get('format') === 'csv') {
            return $this->auditCsv($ws, $query);
        }

        $page = max(1, intval($request->get('page', 1)));
        $total = (clone $query)->count();
        $logs = $query->skip(($page - 1) * static::AUDIT_PAGE_SIZE)->take(static::AUDIT_PAGE_SIZE)->get();
        $base = $this->url($ws, 'audit');
        $params = array_filter($filters, function ($value) {
            return $value !== '';
        });

        $this->setPageInfo(exmtrans('file_server.header'), exmtrans('file_server.audit'), $ws->name, 'fa-history');
        $this->AdminContent($content);
        $content->row(view('exment::file_server.audit', [
            'workspace' => $ws,
            'back_url' => $this->url($ws),
            'action' => $base,
            'filters' => $filters,
            'actions' => collect(Audit::ACTIONS)->mapWithKeys(function ($action) {
                return [$action => Audit::label($action)];
            })->all(),
            'rows' => $logs->map(function (FileAuditLog $log) use ($ws) {
                return $this->auditRow($ws, $log);
            })->all(),
            'total' => $total,
            'page' => $page,
            'prev_url' => $page > 1 ? $base . '?' . http_build_query($params + ['page' => $page - 1]) : null,
            'next_url' => $page * static::AUDIT_PAGE_SIZE < $total ? $base . '?' . http_build_query($params + ['page' => $page + 1]) : null,
            'csv_url' => $base . '?' . http_build_query($params + ['format' => 'csv']),
        ]));
        return $content;
    }

    /**
     * @return array<string, mixed>
     */
    protected function auditRow(FileWorkspace $ws, FileAuditLog $log): array
    {
        $detail = is_string($log->detail) ? (array)json_decode($log->detail, true) : [];
        $node = isset($log->node_id) ? FileNode::query()->whereNull('trashed_at')->find($log->node_id) : null;
        return [
            'created_at' => $log->created_at,
            'action' => $log->action,
            'action_label' => Audit::label(strval($log->action)),
            'user' => isset($log->user_id) ? $this->userName($log->user_id) : (isset($log->link_id) ? exmtrans('file_server.audit_by_link') : exmtrans('file_server.audit_by_system')),
            'node_name' => $log->node_name,
            'node_url' => isset($node) && $node->workspace_id === $ws->id ? $this->nodeUrl($ws, $node) : null,
            'detail' => count($detail) > 0 ? json_encode($detail, JSON_UNESCAPED_UNICODE) : '',
            'ip' => $log->ip,
        ];
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<FileAuditLog> $query
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    protected function auditCsv(FileWorkspace $ws, $query)
    {
        Audit::log(Audit::DOWNLOAD, $ws, null, $this->library()->access()->userId(), ['audit_csv' => true]);
        $name = 'file_server_audit_' . $ws->id . '_' . now()->format('YmdHis') . '.csv';
        return response()->streamDownload(function () use ($ws, $query) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            // Excel opens it as UTF-8
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                exmtrans('file_server.audit_at'), exmtrans('file_server.audit_action'), exmtrans('file_server.audit_user'),
                exmtrans('file_server.audit_node'), exmtrans('file_server.audit_detail'), 'IP', 'User-Agent',
            ]);

            foreach ($query->take(static::AUDIT_CSV_MAX)->cursor() as $log) {
                /** @var FileAuditLog $log */
                $row = $this->auditRow($ws, $log);
                fputcsv($out, array_map([static::class, 'csvCell'], [
                    isset($row['created_at']) ? $row['created_at']->format('Y-m-d H:i:s') : '',
                    $row['action_label'], $row['user'], strval($row['node_name']), $row['detail'], strval($row['ip']), strval($log->user_agent),
                ]));
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * A cell that Excel does not run as a formula.
     */
    public static function csvCell(mixed $value): string
    {
        $value = strval($value);
        return preg_match('/\A[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
    }

    // ================================================================ the approvals

    /**
     * The files of the workspace that wait for an approval the user may give.
     *
     * @return Content|\Symfony\Component\HttpFoundation\Response
     */
    public function approvals(Request $request, Content $content, string $workspace)
    {
        try {
            $ws = $this->workspace($workspace);
            $library = $this->library();
            $library->access()->authorize($ws, null, Level::VIEW);
            $nodes = Governance::waiting($library, $ws);
        } catch (FileServerException $e) {
            return $this->deny($e);
        }
        $this->setPageInfo(exmtrans('file_server.header'), exmtrans('file_server.approvals'), $ws->name, 'fa-check-square-o');
        $this->AdminContent($content);
        $content->row(view('exment::file_server.approvals', [
            'workspace' => $ws,
            'back_url' => $this->url($ws),
            'rows' => $nodes->map(function (FileNode $node) use ($ws) {
                return [
                    'name' => $node->name,
                    'url' => $this->nodeUrl($ws, $node),
                    'user' => $this->userName($node->created_user_id) ?: exmtrans('file_server.audit_by_link'),
                    'created_at' => $node->created_at,
                    'size' => $node->size,
                    'approve_url' => $this->nodeUrl($ws, $node, 'approve'),
                    'reject_url' => $this->nodeUrl($ws, $node, 'reject'),
                ];
            })->all(),
        ]));
        return $content;
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function approve(Request $request, string $workspace, string $node)
    {
        return $this->nodeAction($workspace, $node, function (FileWorkspace $ws, FileNode $item) use ($request) {
            Governance::approve($this->library(), $ws, $item, $request->input('comment'));
            return ['message' => exmtrans('file_server.approved')];
        });
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function reject(Request $request, string $workspace, string $node)
    {
        return $this->nodeAction($workspace, $node, function (FileWorkspace $ws, FileNode $item) use ($request) {
            Governance::reject($this->library(), $ws, $item, $request->input('comment'));
            return ['message' => exmtrans('file_server.rejected')];
        });
    }

    /**
     * The legal hold and the approval of a folder or file.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function rules(Request $request, string $workspace, string $node)
    {
        return $this->nodeAction($workspace, $node, function (FileWorkspace $ws, FileNode $item) use ($request) {
            $input = ['legal_hold' => $request->boolean('legal_hold')];
            if ($item->isFolder()) {
                $input['requires_approval'] = $request->boolean('requires_approval');
            }
            Governance::setRules($this->library(), $ws, $item, $input);
            return ['message' => exmtrans('common.message.success_execute')];
        });
    }

    // ================================================================ follows and comments

    /**
     * Follow the workspace (no node), or a folder or file.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function follow(Request $request, string $workspace, ?string $node = null)
    {
        return $this->run(function () use ($workspace, $node) {
            $ws = $this->workspace($workspace);
            $item = isset($node) ? $this->library()->findNode($ws, intval($node)) : null;
            $following = (new Collaboration($this->library()))->toggleFollow($ws, $item);
            return [
                'following' => $following,
                'message' => exmtrans($following ? 'file_server.followed' : 'file_server.unfollowed'),
            ];
        });
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function addComment(Request $request, string $workspace, string $node)
    {
        return $this->nodeAction($workspace, $node, function (FileWorkspace $ws, FileNode $item) use ($request) {
            (new Collaboration($this->library()))->addComment($ws, $item, strval($request->input('body', '')));
            return ['message' => exmtrans('file_server.comment_added')];
        });
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function deleteComment(Request $request, string $workspace, string $node, string $comment)
    {
        return $this->nodeAction($workspace, $node, function (FileWorkspace $ws, FileNode $item) use ($comment) {
            $row = FileComment::query()->where('node_id', $item->id)->find(intval($comment));
            if (!isset($row)) {
                throw FileServerException::notFound();
            }
            (new Collaboration($this->library()))->deleteComment($ws, $item, $row);
            return ['message' => exmtrans('common.message.success_execute')];
        });
    }
}
