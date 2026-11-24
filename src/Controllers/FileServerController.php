<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\FileMetadataField;
use Exceedone\Exment\Model\FileNode;
use Exceedone\Exment\Model\FileNodeText;
use Exceedone\Exment\Model\FileNodeVersion;
use Exceedone\Exment\Model\FilePermission;
use Exceedone\Exment\Model\FileWorkspace;
use Exceedone\Exment\Services\FileServer\Access;
use Exceedone\Exment\Services\FileServer\Audit;
use Exceedone\Exment\Services\FileServer\Collaboration;
use Exceedone\Exment\Services\FileServer\Governance;
use Exceedone\Exment\Services\FileServer\DataLinks;
use Exceedone\Exment\Services\FileServer\FileSearch;
use Exceedone\Exment\Services\FileServer\FileServerException;
use Exceedone\Exment\Services\FileServer\Level;
use Exceedone\Exment\Services\FileServer\Library;
use Exceedone\Exment\Services\FileServer\ViewOnly;
use Exceedone\Exment\Services\FileServer\Metadata;
use Exceedone\Exment\Services\FileServer\Paths;
use Exceedone\Exment\Services\FileServer\Preview;
use Exceedone\Exment\Services\FileServer\ShareLinks;
use Exceedone\Exment\Services\FileServer\TextFiles;
use Exceedone\Exment\Model\FileShareLink;
use Exceedone\Exment\Services\FileServer\Uploader;
use ExmentAdminCore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * File server (ISS00293): workspaces like the sites of SharePoint, with
 * folders, files, permissions, a trash, versions, check out and previews.
 *
 * The pages are plain views; the operations are called from them with
 * fetch() and answer JSON: {result, message, redirect?}.
 */
class FileServerController extends AdminControllerBase
{
    protected ?Library $library = null;

    public function __construct()
    {
        $this->setPageInfo(exmtrans('file_server.header'), exmtrans('file_server.header'), exmtrans('file_server.description'), 'fa-folder-open');
    }

    protected function library(): Library
    {
        // the controller may be kept for the next request (the route keeps it):
        // a library of another user is not used again
        $userId = \Exceedone\Exment\Facades\ExmentFacade::getUserId();
        $userId = isset($userId) ? intval($userId) : null;
        if (!isset($this->library) || $this->library->access()->userId() !== $userId) {
            $this->library = new Library(Access::current());
        }
        return $this->library;
    }

    // ================================================================ pages

    /**
     * The workspaces of the user, their favorites, the files changed lately and
     * the folders and files given to them.
     *
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        $library = $this->library();
        $access = $library->access();
        $workspaces = $access->workspaces()->map(function (FileWorkspace $workspace) use ($access) {
            return [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'description' => $workspace->description,
                'level' => Level::label($access->level($workspace)),
                'updated_at' => $workspace->updated_at,
                'url' => $this->pageUrl($workspace),
                'link' => (new Paths($this->library()->access()))->link($workspace),
            ];
        });

        $this->AdminContent($content);
        $content->row(view('exment::file_server.index', [
            'workspaces' => $workspaces,
            'favorites' => $this->nodeRows($library->favorites()),
            'recent' => $this->nodeRows($library->recentFiles()),
            'shared' => $this->nodeRows($access->sharedNodes()),
            'create_url' => $access->canCreateWorkspace() ? admin_urls('file_server', 'create') : null,
        ]));
        return $content;
    }

    /**
     * The form of a new workspace.
     *
     * @return Content|\Symfony\Component\HttpFoundation\Response
     */
    public function create(Request $request, Content $content)
    {
        if (!$this->library()->access()->canCreateWorkspace()) {
            return $this->deny();
        }
        $this->setPageInfo(exmtrans('file_server.header'), exmtrans('file_server.create_workspace'), exmtrans('file_server.create_workspace_description'), 'fa-folder-open');
        $this->AdminContent($content);
        $content->row(view('exment::file_server.settings', [
            'workspace' => null,
            // the user who makes it manages it (store keeps it so): shown, with the name
            'members' => [[
                'target_type' => FilePermission::TARGET_USER,
                'target_id' => $this->library()->access()->userId(),
                'name' => $this->userName($this->library()->access()->userId()),
                'level' => Level::MANAGE,
                'level_label' => Level::label(Level::MANAGE),
            ]],
            'action' => admin_url('file_server'),
            'back_url' => admin_url('file_server'),
            'delete_url' => null,
            'used_bytes' => 0,
            'fields' => [],
            'fields_url' => null,
        ] + $this->pickerData()));
        return $content;
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function store(Request $request)
    {
        return $this->run(function () use ($request) {
            $workspace = $this->library()->createWorkspace($this->workspaceInput($request));
            $members = $this->permissionInput($request);
            if (count($members) > 0) {
                // the user who makes it stays a manager (once, at MANAGE)
                $me = $this->library()->access()->userId();
                $members = array_values(array_filter($members, function ($m) use ($me) {
                    return !($m['target_type'] === FilePermission::TARGET_USER && intval($m['target_id']) === $me);
                }));
                $members[] = ['target_type' => FilePermission::TARGET_USER, 'target_id' => $me, 'level' => Level::MANAGE];
                $this->library()->setPermissions($workspace, null, $members);
            }
            return ['message' => exmtrans('common.message.success_execute'), 'redirect' => $this->pageUrl($workspace)];
        });
    }

    /**
     * A folder (or the top) of a workspace: its folders and files.
     *
     * @return Content|\Symfony\Component\HttpFoundation\Response
     */
    public function folder(Request $request, Content $content, string $workspace, ?string $node = null)
    {
        try {
            $ws = $this->workspace($workspace);
            $library = $this->library();
            $folder = isset($node) ? $library->findNode($ws, intval($node)) : null;
            if (isset($folder) && !$folder->isFolder()) {
                return redirect($this->nodeUrl($ws, $folder));
            }
        } catch (FileServerException $e) {
            return $this->deny($e);
        }
        // the address with numbers: to the path (ISS02815)
        $redirect = $this->toPath($request, $ws, $folder);
        return $redirect ?? $this->folderPage($request, $content, $ws, $folder);
    }

    /**
     * A folder (or the top) found by its path (ISS02815):
     * /file_server/<workspace>/<folder>/.../<file>. ?n=<id> names the item for
     * good. What the user may not see is not there (404, as a wrong path).
     *
     * @return Content|\Symfony\Component\HttpFoundation\Response
     */
    public function path(Request $request, Content $content, string $path)
    {
        $id = $request->query('n');
        $id = is_scalar($id) && preg_match('/^[1-9][0-9]{0,9}$/', strval($id)) === 1 ? intval($id) : null;
        if ($request->query->has('n') && !isset($id)) {
            abort(404);
        }
        $paths = new Paths($this->library()->access());
        try {
            [$ws, $node] = $paths->resolve($this->library()->access(), $path, $id);
        } catch (FileServerException $e) {
            abort(404);
        }
        // not the path it has now (an old one, another case): to that one;
        // no path for it (too long, a name another workspace has too): to the number
        $url = $paths->url($ws, $node);
        $asked = array_values(array_filter(explode('/', $path), function ($segment) {
            return $segment !== '';
        }));
        $current = isset($url) ? array_merge([$paths->workspaceSegment($ws)], isset($node) ? $paths->names($node) : []) : null;
        if ($asked !== $current) {
            $query = $request->query();
            if (!isset($url)) {
                unset($query['n']);
            }
            return redirect($this->withQuery($url ?? Paths::numberUrl($ws, $node), $query), 302);
        }
        if (isset($node) && $node->isFile()) {
            return $this->nodePage($request, $content, $ws, $node);
        }
        return $this->folderPage($request, $content, $ws, $node);
    }

    /**
     * The address with numbers of a page, asked by the browser (not by fetch()):
     * to the path, with the number of the item (so it opens it after a rename).
     * 302, not 301: where it leads changes (the names, what the user may see),
     * and the address with numbers always opens it.
     *
     * @return \Symfony\Component\HttpFoundation\Response|null
     */
    protected function toPath(Request $request, FileWorkspace $ws, ?FileNode $node)
    {
        if (!$request->isMethod('GET') || ($request->ajax() && !$request->pjax())) {
            return null;
        }
        if (!$this->library()->access()->can($ws, $node, Level::VIEW)) {
            return null;
        }
        $url = (new Paths($this->library()->access()))->url($ws, $node);
        if (!isset($url)) {
            return null;
        }
        $query = $request->query();
        if (isset($node)) {
            $query = ['n' => $node->id] + array_diff_key($query, ['n' => true]);
        }
        return redirect($this->withQuery($url, $query), 302);
    }

    /**
     * @param array<mixed> $query
     */
    protected function withQuery(string $url, array $query): string
    {
        return count($query) > 0 ? $url . '?' . http_build_query($query) : $url;
    }

    /**
     * @return Content|\Symfony\Component\HttpFoundation\Response
     */
    protected function folderPage(Request $request, Content $content, FileWorkspace $ws, ?FileNode $folder)
    {
        $library = $this->library();
        try {
            $sort = strval($request->get('sort', 'name'));
            $direction = strval($request->get('direction', 'asc'));
            $children = $library->children($ws, $folder, $sort, $direction);
        } catch (FileServerException $e) {
            return $this->deny($e);
        }

        // the columns of the workspace: narrow, sort and group by them (ISS00378)
        $fields = Metadata::fields($ws);
        $filters = array_filter((array)$request->get('meta', []), function ($value) {
            return is_scalar($value) && strval($value) !== '';
        });
        $children = Metadata::filter($children, $fields, $filters);
        if (str_starts_with($sort, 'meta:')) {
            $sortField = $fields->firstWhere('name', substr($sort, 5));
            if (isset($sortField)) {
                $children = Metadata::sort($children, $sortField, $direction);
            }
        }
        $groupField = $fields->first(function (FileMetadataField $field) use ($request) {
            return $field->name === strval($request->get('group', ''))
                && ($field->hasChoices() || $field->field_type === FileMetadataField::TYPE_BOOLEAN);
        });
        $groups = null;
        if (isset($groupField)) {
            $groups = array_map(function ($group) use ($ws, $fields) {
                return ['label' => $group['label'], 'items' => $this->nodeRows($group['nodes'], $ws, $fields)];
            }, Metadata::group($children, $groupField));
        }

        $access = $library->access();
        $level = $access->level($ws, $folder);
        $this->setPageInfo(exmtrans('file_server.header'), $ws->name, $ws->description ?: ' ', 'fa-folder-open');
        $this->AdminContent($content);
        $content->row(view('exment::file_server.folder', [
            'workspace' => $ws,
            'folder' => $folder,
            'breadcrumbs' => $this->breadcrumbs($ws, $folder, $this->library()->access()),
            'items' => $this->nodeRows($children, $ws, $fields),
            'groups' => $groups,
            'group' => isset($groupField) ? $groupField->name : null,
            'fields' => $this->fieldRows($fields),
            'filters' => $filters,
            'query' => array_filter(['meta' => $filters, 'group' => isset($groupField) ? $groupField->name : null]),
            'search_url' => admin_urls('file_server', 'search'),
            'can_upload' => $level >= Level::UPLOAD,
            'can_edit' => $level >= Level::EDIT,
            'can_manage_workspace' => $access->can($ws, null, Level::MANAGE),
            // phase 5 (ISS00379)
            'following' => (new Collaboration($library))->isFollowing($ws, $folder),
            'follow_url' => isset($folder) ? $this->nodeUrl($ws, $folder, 'follow') : $this->url($ws, 'follow'),
            'audit_url' => $access->can($ws, null, Level::MANAGE) ? $this->url($ws, 'audit') : null,
            'approvals_url' => $this->url($ws, 'approvals'),
            'waiting_count' => Governance::waiting($library, $ws)->count(),
            'needs_approval' => Governance::needsApproval($folder),
            'held' => $ws->legal_hold || (isset($folder) && Governance::isHeld($ws, $folder)),
            'sort' => $sort,
            'direction' => $direction,
            'base_url' => $this->url($ws),
            'folder_url' => $this->pageUrl($ws, $folder),
            // "リンクをコピー" (ISS02815)
            'link' => (new Paths($this->library()->access()))->link($ws, $folder),
            'link_name' => isset($folder) ? $folder->name : $ws->name,
            'parent_id' => isset($folder) ? $folder->id : null,
            'chunk_size' => Uploader::CHUNK_SIZE,
            'max_file_size' => Uploader::maxFileSize(),
            'quota' => $ws->quota_bytes,
            'used_bytes' => isset($ws->quota_bytes) ? $ws->usedBytes() : null,
        ]));
        return $content;
    }

    /**
     * A file: the preview, the versions, the check out and the permissions.
     * A folder: its permissions.
     *
     * @return Content|\Symfony\Component\HttpFoundation\Response
     */
    public function showNode(Request $request, Content $content, string $workspace, string $node)
    {
        try {
            $ws = $this->workspace($workspace);
            $item = $this->library()->findNode($ws, intval($node));
        } catch (FileServerException $e) {
            return $this->deny($e);
        }
        // a file: its page is at its path (ISS02815). A folder: this is the page of
        // its details and permissions; its path is the list of what is in it
        $redirect = $item->isFile() ? $this->toPath($request, $ws, $item) : null;
        return $redirect ?? $this->nodePage($request, $content, $ws, $item);
    }

    /**
     * @return Content|\Symfony\Component\HttpFoundation\Response
     */
    protected function nodePage(Request $request, Content $content, FileWorkspace $ws, FileNode $item)
    {
        $library = $this->library();
        $access = $library->access();
        $level = $access->level($ws, $item);
        $version = $item->isFile() ? $item->current_version : null;
        if ($item->isFile()) {
            Audit::log(Audit::VIEW, $ws, $item, $access->userId());
        }
        $collaboration = new Collaboration($library);
        $kind = isset($version) ? Preview::kind($version->mime_type, $item->name) : Preview::KIND_NONE;
        // who may see and not download: what is made from the file, not the file (ViewOnly)
        if ($item->isFile() && !Governance::canDownload($access, $ws, $item)) {
            $kind = ViewOnly::kind($kind, $version);
        }
        // a file from a request link: its preview and text are made when a manager asks (ISS00410, ISS00433)

        $text = null;
        if ($kind === Preview::KIND_TEXT && isset($version) && $version->size <= Preview::TEXT_PREVIEW_BYTES) {
            try {
                $text = Library::disk()->get($version->path);
                if (is_string($text) && !mb_check_encoding($text, 'UTF-8')) {
                    $text = mb_convert_encoding($text, 'UTF-8', 'SJIS-win,EUC-JP,UTF-8');
                }
            } catch (\Throwable $e) {
                $text = null;
            }
        }

        $this->setPageInfo(exmtrans('file_server.header'), $item->name, $ws->name, $item->isFolder() ? 'fa-folder' : 'fa-file-o');
        $this->AdminContent($content);
        $content->row(view('exment::file_server.show', [
            'workspace' => $ws,
            'node' => $item,
            'breadcrumbs' => $this->breadcrumbs($ws, $item, $access),
            'base_url' => $this->url($ws),
            'node_url' => $this->nodeUrl($ws, $item),
            'parent_url' => $this->pageUrl($ws, isset($item->parent_id) ? FileNode::find($item->parent_id) : null),
            'page_url' => $this->pageUrl($ws, $item),
            'link' => (new Paths($this->library()->access()))->link($ws, $item),
            'level' => $level,
            'level_label' => Level::label($level),
            'kind' => $kind,
            'text' => $text,
            'has_pdf_preview' => isset($version) && isset($version->preview_path),
            'rendition_status' => isset($version) ? $version->rendition_status : null,
            'versions' => $item->isFile() ? $item->versions()->get()->map(function (FileNodeVersion $v) use ($ws, $item) {
                return [
                    'id' => $v->id,
                    'no' => $v->version_no,
                    'size' => $v->size,
                    'comment' => $v->comment,
                    'user' => $this->userName($v->created_user_id),
                    'created_at' => $v->created_at,
                    'current' => $v->id === $item->current_version_id,
                    'download_url' => $this->nodeUrl($ws, $item, 'download') . '?version=' . $v->id,
                    'restore_url' => $this->nodeUrl($ws, $item, 'versions/' . $v->id . '/restore'),
                ];
            })->all() : [],
            'checked_out_by' => isset($item->checked_out_user_id) ? $this->userName($item->checked_out_user_id) : null,
            'checked_out_by_me' => isset($item->checked_out_user_id) && intval($item->checked_out_user_id) === $access->userId(),
            'favorite' => $library->isFavorite($item),
            'can_edit_text' => TextFiles::isEditable($item) && $library->canAddVersion($ws, $item) && Governance::canDownload($access, $ws, $item),
            'openers' => $item->isFile() ? Preview::openers($item) : [],
            // who else may see it: for those who manage it (ISS00433)
            'permissions' => $level >= Level::MANAGE ? $this->permissionRows($library->effectivePermissions($ws, $item)) : [],
            'chunk_size' => Uploader::CHUNK_SIZE,
            'created_user' => $this->userName($item->created_user_id),
            'updated_user' => $this->userName($item->updated_user_id),
            'share_enabled' => ShareLinks::enabled(),
            'share_max_days' => ShareLinks::maxDays(),
            'share_default_days' => ShareLinks::defaultDays(),
            'links' => $this->linkRows($ws, $item),
            'can_share' => ShareLinks::enabled() && $level >= Level::EDIT,
            // as ShareLinks::create: a request link needs EDIT
            'can_request' => ShareLinks::enabled() && $item->isFolder() && $level >= Level::EDIT,
            'fields' => $this->fieldRows(Metadata::fields($ws), (array)($item->metadata ?? [])),
            'data_links' => DataLinks::dataOf($item),
            'data_tables' => $level >= Level::EDIT ? DataLinks::tables()->map(function ($table) {
                return ['name' => $table->table_name, 'label' => $table->table_view_name];
            })->all() : [],
            'data_lookup_url' => admin_urls('file_server', 'data_lookup'),
            'text_status' => $item->isFile() ? FileNodeText::query()->where('node_id', $item->id)->value('status') : null,
            // an Office file without a PDF preview: its text at least (ISS00378)
            'office_text' => $kind === Preview::KIND_OFFICE && !(isset($version) && isset($version->preview_path))
                ? mb_substr(strval(FileNodeText::query()->where('node_id', $item->id)->value('content')), 0, 20000) : null,
            // phase 5 (ISS00379)
            'can_download' => Governance::canDownload($access, $ws, $item),
            'watermark' => $ws->watermark ? trim($this->userName($access->userId()) . ' ' . now()->format('Y/m/d H:i')) : null,
            'following' => $collaboration->isFollowing($ws, $item),
            'follow_url' => $this->nodeUrl($ws, $item, 'follow'),
            'comments' => $collaboration->comments($ws, $item)->map(function (\Exceedone\Exment\Model\FileComment $comment) use ($ws, $item, $access, $level) {
                return [
                    'body' => $comment->body,
                    'user' => $this->userName($comment->created_user_id),
                    'created_at' => $comment->created_at,
                    'delete_url' => (intval($comment->created_user_id) === $access->userId() || $level >= Level::MANAGE)
                        ? $this->nodeUrl($ws, $item, 'comments/' . $comment->id . '/delete') : null,
                ];
            })->all(),
            'comment_url' => $this->nodeUrl($ws, $item, 'comments'),
            'approval_status' => $item->approval_status,
            'approval_user' => $this->userName($item->approval_user_id),
            'approval_at' => $item->approval_at,
            'approval_comment' => $item->approval_comment,
            'can_approve' => $item->approval_status === FileNode::APPROVAL_DRAFT && $level >= Level::MANAGE,
            'approve_url' => $this->nodeUrl($ws, $item, 'approve'),
            'reject_url' => $this->nodeUrl($ws, $item, 'reject'),
            'rules_url' => $this->nodeUrl($ws, $item, 'rules'),
            'held' => Governance::isHeld($ws, $item),
            'retained_until' => $item->isFile() ? Governance::retainedUntil($ws, $item) : null,
        ] + ($level >= Level::MANAGE ? $this->pickerData($ws, $item) : ['picker_url' => null, 'picker_orgs' => false, 'levels' => Level::options()])));
        return $content;
    }

    /**
     * @return Content|\Symfony\Component\HttpFoundation\Response
     */
    public function trash(Request $request, Content $content, string $workspace)
    {
        try {
            $ws = $this->workspace($workspace);
            $library = $this->library();
            $items = $library->trashItems($ws);
        } catch (FileServerException $e) {
            return $this->deny($e);
        }
        $access = $library->access();
        $this->setPageInfo(exmtrans('file_server.header'), exmtrans('file_server.trash'), $ws->name, 'fa-trash');
        $this->AdminContent($content);
        $content->row(view('exment::file_server.trash', [
            'workspace' => $ws,
            'base_url' => $this->url($ws),
            'trash_days' => $ws->trash_days,
            'items' => $items->map(function (FileNode $node) use ($ws, $access) {
                return [
                    'id' => $node->id,
                    'name' => $node->name,
                    'is_folder' => $node->isFolder(),
                    'size' => $node->size,
                    'trashed_at' => $node->trashed_at,
                    'trashed_user' => $this->userName($node->trashed_user_id),
                    'restore_url' => $this->nodeUrl($ws, $node, 'restore'),
                    'purge_url' => $access->can($ws, $node, Level::MANAGE) ? $this->nodeUrl($ws, $node, 'purge') : null,
                ];
            })->all(),
        ]));
        return $content;
    }

    /**
     * @return Content|\Symfony\Component\HttpFoundation\Response
     */
    public function settings(Request $request, Content $content, string $workspace)
    {
        try {
            $ws = $this->workspace($workspace);
            $this->library()->access()->authorize($ws, null, Level::MANAGE);
        } catch (FileServerException $e) {
            return $this->deny($e);
        }
        $this->setPageInfo(exmtrans('file_server.header'), exmtrans('file_server.workspace_settings'), $ws->name, 'fa-cog');
        $this->AdminContent($content);
        $content->row(view('exment::file_server.settings', [
            'workspace' => $ws,
            'members' => $this->permissionRows($ws->members()->orderByDesc('level')->get()),
            'action' => $this->url($ws, 'settings'),
            'back_url' => $this->url($ws),
            'delete_url' => $this->url($ws),
            'used_bytes' => $ws->usedBytes(),
            'fields' => $this->fieldRows(Metadata::fields($ws)),
            'fields_url' => $this->url($ws, 'metadata_fields'),
            'audit_url' => $this->url($ws, 'audit'),
        ] + $this->pickerData($ws)));
        return $content;
    }

    // ================================================================ operations (JSON)

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function updateSettings(Request $request, string $workspace)
    {
        return $this->run(function () use ($request, $workspace) {
            $ws = $this->workspace($workspace);
            $library = $this->library();
            $library->updateWorkspace($ws, $this->workspaceInput($request));
            Governance::setWorkspaceRules($library, $ws, [
                'retention_days' => $request->input('retention_days'),
                'retention_action' => $request->input('retention_action'),
                'legal_hold' => $request->boolean('legal_hold'),
                'watermark' => $request->boolean('watermark'),
                'download_level' => $request->input('download_level', Level::VIEW),
            ]);
            $library->setPermissions($ws, null, $this->permissionInput($request));
            return ['message' => exmtrans('common.message.success_execute'), 'redirect' => $this->url($ws)];
        });
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function destroyWorkspace(Request $request, string $workspace)
    {
        return $this->run(function () use ($workspace) {
            $this->library()->deleteWorkspace($this->workspace($workspace));
            return ['message' => exmtrans('common.message.success_execute'), 'redirect' => admin_url('file_server')];
        });
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function createFolder(Request $request, string $workspace)
    {
        return $this->run(function () use ($request, $workspace) {
            $ws = $this->workspace($workspace);
            $parent = $this->parentFolder($ws, $request->input('parent_id'));
            $folder = $this->library()->createFolder($ws, $parent, strval($request->input('name')));
            return ['message' => exmtrans('common.message.success_execute'), 'id' => $folder->id];
        });
    }

    /**
     * An upload in one request (small files). "files[]" with "paths[]" (the
     * folders of each file, for an upload of a folder).
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function upload(Request $request, string $workspace)
    {
        return $this->run(function () use ($request, $workspace) {
            $ws = $this->workspace($workspace);
            $parent = $this->parentFolder($ws, $request->input('parent_id'));
            $files = $request->file('files', []);
            $files = is_array($files) ? $files : [$files];
            $paths = (array)$request->input('paths', []);
            $done = [];
            foreach (array_values($files) as $i => $file) {
                if (!$file->isValid()) {
                    throw new FileServerException(exmtrans('file_server.error_upload'));
                }
                $max = Uploader::maxFileSize();
                if ($max > 0 && $file->getSize() > $max) {
                    throw new FileServerException(exmtrans('file_server.error_too_large'), 413);
                }
                $node = $this->library()->storeFile($ws, $parent, $file->getClientOriginalName(), $file->getRealPath(), $request->input('comment'), isset($paths[$i]) ? strval($paths[$i]) : null);
                $done[] = $node->id;
            }
            return ['message' => exmtrans('file_server.uploaded', ['count' => count($done)]), 'ids' => $done];
        });
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function uploadInit(Request $request, string $workspace)
    {
        return $this->run(function () use ($request, $workspace) {
            $ws = $this->workspace($workspace);
            $parent = $this->parentFolder($ws, $request->input('parent_id'));
            $session = (new Uploader($this->library()))->init($ws, $parent, strval($request->input('name')), intval($request->input('size')), $request->input('path'), $request->input('comment'));
            return ['session' => $session->id, 'chunk_size' => Uploader::CHUNK_SIZE, 'received' => 0];
        });
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function uploadStatus(Request $request, string $workspace, string $session)
    {
        return $this->run(function () use ($workspace, $session) {
            $ws = $this->workspace($workspace);
            $upload = (new Uploader($this->library()))->find($ws, strval($session));
            return ['session' => $upload->id, 'received' => $upload->received_size, 'total' => $upload->total_size];
        });
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function uploadChunk(Request $request, string $workspace, string $session)
    {
        return $this->run(function () use ($request, $workspace, $session) {
            $ws = $this->workspace($workspace);
            $uploader = new Uploader($this->library());
            $upload = $uploader->find($ws, strval($session));
            $chunk = $request->file('chunk');
            if (!$chunk instanceof \Illuminate\Http\UploadedFile || !$chunk->isValid()) {
                throw new FileServerException(exmtrans('file_server.error_upload'));
            }
            try {
                $received = $uploader->append($upload, intval($request->input('offset')), strval($chunk->getRealPath()));
            } catch (FileServerException $e) {
                if ($e->status() === 409) {
                    // tell the browser where to go on from
                    return response()->json(['result' => false, 'message' => $e->getMessage(), 'received' => $upload->received_size], 409);
                }
                throw $e;
            }
            return ['received' => $received, 'total' => $upload->total_size];
        });
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function uploadComplete(Request $request, string $workspace, string $session)
    {
        return $this->run(function () use ($workspace, $session) {
            $ws = $this->workspace($workspace);
            $uploader = new Uploader($this->library());
            $node = $uploader->complete($ws, $uploader->find($ws, strval($session)));
            return ['message' => exmtrans('file_server.uploaded', ['count' => 1]), 'id' => $node->id];
        });
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function uploadCancel(Request $request, string $workspace, string $session)
    {
        return $this->run(function () use ($workspace, $session) {
            $ws = $this->workspace($workspace);
            $uploader = new Uploader($this->library());
            $uploader->cancel($uploader->find($ws, strval($session)));
            return [];
        });
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function rename(Request $request, string $workspace, string $node)
    {
        return $this->nodeAction($workspace, $node, function (FileWorkspace $ws, FileNode $item) use ($request) {
            $this->library()->rename($ws, $item, strval($request->input('name')));
            return ['message' => exmtrans('common.message.success_execute')];
        });
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function move(Request $request, string $workspace, string $node)
    {
        return $this->nodeAction($workspace, $node, function (FileWorkspace $ws, FileNode $item) use ($request) {
            $this->library()->move($ws, $item, $this->parentFolder($ws, $request->input('target_id')));
            return ['message' => exmtrans('common.message.success_execute')];
        });
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function copy(Request $request, string $workspace, string $node)
    {
        return $this->nodeAction($workspace, $node, function (FileWorkspace $ws, FileNode $item) use ($request) {
            $copy = $this->library()->copy($ws, $item, $this->parentFolder($ws, $request->input('target_id')));
            return ['message' => exmtrans('common.message.success_execute'), 'id' => $copy->id];
        });
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function trashNode(Request $request, string $workspace, string $node)
    {
        return $this->nodeAction($workspace, $node, function (FileWorkspace $ws, FileNode $item) {
            $this->library()->trash($ws, $item);
            return ['message' => exmtrans('file_server.trashed')];
        });
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function restore(Request $request, string $workspace, string $node)
    {
        return $this->run(function () use ($workspace, $node) {
            $ws = $this->workspace($workspace);
            $item = $this->library()->findNode($ws, intval($node), true, Level::EDIT);
            $this->library()->restore($ws, $item);
            return ['message' => exmtrans('file_server.restored')];
        });
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function purge(Request $request, string $workspace, string $node)
    {
        return $this->run(function () use ($workspace, $node) {
            $ws = $this->workspace($workspace);
            $item = $this->library()->findNode($ws, intval($node), true, Level::MANAGE);
            $this->library()->purge($ws, $item);
            return ['message' => exmtrans('common.message.success_execute')];
        });
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function favorite(Request $request, string $workspace, string $node)
    {
        return $this->nodeAction($workspace, $node, function (FileWorkspace $ws, FileNode $item) {
            return ['favorite' => $this->library()->toggleFavorite($ws, $item)];
        });
    }

    /**
     * A new version from the file page (small files; the large ones use the upload in parts with the same name).
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function uploadVersion(Request $request, string $workspace, string $node)
    {
        return $this->nodeAction($workspace, $node, function (FileWorkspace $ws, FileNode $item) use ($request) {
            $file = $request->file('file');
            if (!$file instanceof \Illuminate\Http\UploadedFile || !$file->isValid()) {
                throw new FileServerException(exmtrans('file_server.error_upload'));
            }
            // an editor sends the version it started from: refuse when someone saved after it
            $base = $request->input('base_version');
            if ($base !== null && $base !== '' && intval($base) !== intval($item->current_version_id)) {
                throw new FileServerException(exmtrans('file_server.error_version_conflict'), 409);
            }
            if ($request->boolean('checkin')) {
                $this->library()->checkin($ws, $item, strval($file->getRealPath()), $request->input('comment'));
            } else {
                $this->library()->addVersion($ws, $item, strval($file->getRealPath()), $request->input('comment'));
            }
            $item->refresh();
            return ['message' => exmtrans('file_server.version_added'), 'version' => $item->current_version_id, 'version_no' => optional($item->current_version)->version_no];
        });
    }

    /**
     * "New": an empty text, Markdown or CSV file in the folder (ISS00434). It
     * opens in the editor.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function createTextFile(Request $request, string $workspace)
    {
        return $this->run(function () use ($request, $workspace) {
            $ws = $this->workspace($workspace);
            $parent = $this->parentFolder($ws, $request->input('parent_id'));
            $type = $this->stringInput($request, 'type');
            $node = (new TextFiles($this->library()))->create($ws, $parent, $this->stringInput($request, 'name'), $type);
            // a text opens in the editor; an Office file on its page (its preview, and its editor when there is one)
            $redirect = TextFiles::isOffice($type) ? $this->pageUrl($ws, $node) : $this->nodeUrl($ws, $node, 'edit');
            return ['message' => exmtrans('file_server.text_created', ['name' => $node->name]), 'id' => $node->id, 'version' => $node->current_version_id, 'redirect' => $redirect];
        });
    }

    /**
     * The page to write a text, Markdown or CSV file in the browser. Those who
     * may not add a version now (no permission to edit, checked out by another
     * user, an approved file in a folder that needs an approval) read it only.
     * Those who may not download it see it in the file page (its watermark).
     *
     * @return Content|\Symfony\Component\HttpFoundation\Response
     */
    public function editText(Request $request, Content $content, string $workspace, string $node)
    {
        try {
            $ws = $this->workspace($workspace);
            $library = $this->library();
            $item = $library->findNode($ws, intval($node));
        } catch (FileServerException $e) {
            return $this->deny($e);
        }
        $access = $library->access();
        if (!Governance::canDownload($access, $ws, $item)) {
            return redirect($this->pageUrl($ws, $item));
        }
        try {
            $file = (new TextFiles($library))->read($ws, $item);
        } catch (FileServerException $e) {
            // too large, another encoding or another kind: the file page, with the reason
            admin_error(exmtrans('common.error'), $e->getMessage());
            return redirect($this->nodeUrl($ws, $item));
        }
        $lockedByOther = isset($item->checked_out_user_id) && intval($item->checked_out_user_id) !== $access->userId();
        $canEdit = $library->canAddVersion($ws, $item);
        Audit::log(Audit::VIEW, $ws, $item, $access->userId());

        $this->setPageInfo(exmtrans('file_server.header'), $item->name, $ws->name, 'fa-pencil');
        $this->AdminContent($content);
        $content->row(view('exment::file_server.edit', [
            'workspace' => $ws,
            'node' => $item,
            'breadcrumbs' => $this->breadcrumbs($ws, $item),
            'node_url' => $this->nodeUrl($ws, $item),
            'text' => $file['text'],
            'version' => $file['version'],
            'version_no' => optional($item->current_version)->version_no,
            'markdown' => $item->extension() === 'md',
            'can_edit' => $canEdit,
            'checked_out_by' => $lockedByOther ? $this->userName($item->checked_out_user_id) : null,
            'needs_manager' => !$canEdit && !$lockedByOther && $library->versionNeedsManager($ws, $item),
            'max_bytes' => TextFiles::TEXT_EDIT_BYTES,
            'preview_bytes' => TextFiles::PREVIEW_BYTES,
            'watermark' => $ws->watermark ? trim($this->userName($access->userId()) . ' ' . now()->format('Y/m/d H:i')) : null,
            'new_file_url' => $this->url($ws, 'text_files'),
            'parent_id' => $item->parent_id,
        ]));
        return $content;
    }

    /**
     * Save the text written in the browser as a new version. "base_version"
     * is the version the editor opened: a save after someone else saved is
     * refused. The same text adds no version.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function saveText(Request $request, string $workspace, string $node)
    {
        return $this->nodeAction($workspace, $node, function (FileWorkspace $ws, FileNode $item) use ($request) {
            $base = $this->stringInput($request, 'base_version');
            if (preg_match('/^[0-9]+$/', $base) !== 1) {
                throw new FileServerException(exmtrans('file_server.error_version_conflict'), 409);
            }
            $comment = $this->stringInput($request, 'comment');
            $result = (new TextFiles($this->library()))->save($ws, $item, $this->postedText($request), intval($base), $comment === '' ? null : $comment);
            $item = $result['node'];
            return [
                'message' => exmtrans($result['changed'] ? 'file_server.version_added' : 'file_server.text_unchanged'),
                'changed' => $result['changed'],
                'version' => $item->current_version_id,
                'version_no' => optional($item->current_version)->version_no,
            ];
        });
    }

    /**
     * The text the editor sends. It comes as a file part, so that its bytes are
     * kept as they are: a form field would have its line breaks made CRLF by
     * the browser and its last line break trimmed by the server.
     */
    protected function postedText(Request $request): string
    {
        $file = $request->file('text');
        if ($file instanceof \Illuminate\Http\UploadedFile) {
            if (!$file->isValid()) {
                throw new FileServerException(exmtrans('file_server.error_upload'));
            }
            if (intval($file->getSize()) > TextFiles::TEXT_EDIT_BYTES) {
                throw new FileServerException(exmtrans('file_server.error_text_too_large', ['size' => round(TextFiles::TEXT_EDIT_BYTES / 1048576, 1)]), 413);
            }
            return strval(file_get_contents(strval($file->getRealPath())));
        }
        return $this->stringInput($request, 'text');
    }

    /**
     * A text input; anything else (an array) is refused, not a server error.
     */
    protected function stringInput(Request $request, string $key): string
    {
        $value = $request->input($key);
        if ($value === null) {
            return '';
        }
        if (!is_string($value) && !is_int($value)) {
            throw new FileServerException(exmtrans('file_server.error_input'), 422);
        }
        return strval($value);
    }

    /**
     * The preview of a Markdown text being written (for those who may see the
     * file; the route limits how often it is asked).
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function markdownPreview(Request $request, string $workspace, string $node)
    {
        return $this->nodeAction($workspace, $node, function (FileWorkspace $ws, FileNode $item) use ($request) {
            if (!TextFiles::isEditable($item) || $item->extension() !== 'md') {
                throw FileServerException::notFound();
            }
            return ['html' => TextFiles::markdownHtml($this->stringInput($request, 'text'))];
        });
    }

    /**
     * What an editor needs to know of a file (the Office of the Groupware, ISS00233):
     * its name, the current version, what the user may do and the addresses to use.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function info(Request $request, string $workspace, string $node)
    {
        return $this->nodeAction($workspace, $node, function (FileWorkspace $ws, FileNode $item) {
            if (!$item->isFile()) {
                throw FileServerException::notFound();
            }
            $access = $this->library()->access();
            $level = $access->level($ws, $item);
            $lockedByOther = isset($item->checked_out_user_id) && intval($item->checked_out_user_id) !== $access->userId();
            $version = $item->current_version;
            return [
                'id' => $item->id,
                'workspace_id' => $ws->id,
                'workspace' => $ws->name,
                'name' => $item->name,
                'extension' => $item->extension(),
                'size' => $item->size,
                'version' => $item->current_version_id,
                'version_no' => isset($version) ? $version->version_no : null,
                'level' => $level,
                'can_edit' => $level >= Level::EDIT && !$lockedByOther,
                'checked_out_by' => isset($item->checked_out_user_id) ? $this->userName($item->checked_out_user_id) : null,
                'checked_out_by_me' => isset($item->checked_out_user_id) && !$lockedByOther,
                'urls' => [
                    'page' => $this->nodeUrl($ws, $item),
                    'folder' => isset($item->parent_id) ? $this->url($ws, 'folder', $item->parent_id) : $this->url($ws),
                    'download' => $this->nodeUrl($ws, $item, 'download'),
                    'version' => $this->nodeUrl($ws, $item, 'version'),
                    'checkout' => $this->nodeUrl($ws, $item, 'checkout'),
                    'checkin' => $this->nodeUrl($ws, $item, 'checkin'),
                    'rename' => $this->nodeUrl($ws, $item, 'rename'),
                ],
            ];
        });
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function restoreVersion(Request $request, string $workspace, string $node, string $version)
    {
        return $this->nodeAction($workspace, $node, function (FileWorkspace $ws, FileNode $item) use ($version) {
            $v = FileNodeVersion::query()->where('node_id', $item->id)->find(intval($version));
            if (!isset($v)) {
                throw FileServerException::notFound();
            }
            $this->library()->restoreVersion($ws, $item, $v);
            return ['message' => exmtrans('file_server.version_restored', ['no' => $v->version_no])];
        });
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function makePreview(Request $request, string $workspace, string $node)
    {
        return $this->nodeAction($workspace, $node, function (FileWorkspace $ws, FileNode $item) {
            $this->library()->access()->authorize($ws, $item, Level::MANAGE);
            $version = $item->isFile() ? $item->current_version : null;
            if (isset($version) && $version->rendition_status === FileNodeVersion::RENDITION_HELD) {
                $version->rendition_status = null;
                $version->save();
                Preview::queueRenditions($version);
                FileSearch::queueText($version);
            }
            return ['message' => exmtrans('file_server.preview_requested')];
        });
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function checkout(Request $request, string $workspace, string $node)
    {
        return $this->nodeAction($workspace, $node, function (FileWorkspace $ws, FileNode $item) {
            $this->library()->checkout($ws, $item);
            return ['message' => exmtrans('file_server.checked_out')];
        });
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function checkin(Request $request, string $workspace, string $node)
    {
        return $this->nodeAction($workspace, $node, function (FileWorkspace $ws, FileNode $item) {
            $this->library()->checkin($ws, $item);
            return ['message' => exmtrans('file_server.checked_in')];
        });
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function discardCheckout(Request $request, string $workspace, string $node)
    {
        return $this->nodeAction($workspace, $node, function (FileWorkspace $ws, FileNode $item) {
            $this->library()->discardCheckout($ws, $item);
            return ['message' => exmtrans('file_server.checked_in')];
        });
    }

    /**
     * mode: "inherit" (take those of the parent again), "break" (its own,
     * starting from those it takes now) or "set" (its own: the entries).
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function updatePermissions(Request $request, string $workspace, string $node)
    {
        return $this->nodeAction($workspace, $node, function (FileWorkspace $ws, FileNode $item) use ($request) {
            $library = $this->library();
            switch (strval($request->input('mode'))) {
                case 'inherit':
                    $library->restoreInheritance($ws, $item);
                    break;
                case 'break':
                    $library->breakInheritance($ws, $item);
                    break;
                default:
                    $library->setPermissions($ws, $item, $this->permissionInput($request));
            }
            return ['message' => exmtrans('common.message.success_execute')];
        });
    }

    /**
     * Make a share link (kind=share) or a request link (kind=request, folders).
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function createLink(Request $request, string $workspace, string $node)
    {
        return $this->nodeAction($workspace, $node, function (FileWorkspace $ws, FileNode $item) use ($request) {
            $link = (new ShareLinks($this->library()))->create($ws, $item, strval($request->input('kind', FileShareLink::KIND_SHARE)), [
                'expires_at' => $request->input('expires_at'),
                'password' => $request->input('password'),
                'allow_download' => $request->boolean('allow_download'),
                'max_access' => $request->input('max_access'),
                'message' => $request->input('message'),
            ]);
            return ['message' => exmtrans('file_server.share_created'), 'url' => $link->url()];
        });
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function revokeLink(Request $request, string $workspace, string $node, string $link)
    {
        return $this->nodeAction($workspace, $node, function (FileWorkspace $ws, FileNode $item) use ($link) {
            $row = FileShareLink::query()->where('node_id', $item->id)->find(intval($link));
            if (!isset($row)) {
                throw FileServerException::notFound();
            }
            (new ShareLinks($this->library()))->revoke($ws, $row);
            return ['message' => exmtrans('file_server.share_revoked')];
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function linkRows(FileWorkspace $ws, FileNode $item): array
    {
        if (!ShareLinks::enabled()) {
            return [];
        }
        $access = $this->library()->access();
        $manages = $access->can($ws, $item, \Exceedone\Exment\Services\FileServer\Level::MANAGE);
        return (new ShareLinks($this->library()))->linksOf($ws, $item)->map(function (FileShareLink $link) use ($ws, $item, $access, $manages) {
            return [
                'id' => $link->id,
                'kind' => $link->kind,
                // the url again: to its maker and to who manages the folder or file
                'url' => $manages || intval($link->created_user_id) === $access->userId() ? $link->url() : null,
                'active' => $link->isActive(),
                'state' => isset($link->revoked_at) ? exmtrans('file_server.share_state_revoked')
                    : ($link->isExpired() ? exmtrans('file_server.share_state_expired')
                    : ($link->isUsedUp() ? exmtrans('file_server.share_state_used_up') : exmtrans('file_server.share_state_active'))),
                'expires_at' => $link->expires_at,
                // a link with no end (made before the end was needed): marked for who manages it
                'no_expiry' => !isset($link->expires_at) && $manages,
                'password' => $link->hasPassword(),
                'allow_download' => $link->allow_download,
                'access' => $link->access_count . (isset($link->max_access) ? ' / ' . $link->max_access : ''),
                'user' => $this->userName($link->created_user_id),
                'created_at' => $link->created_at,
                'revoke_url' => $this->nodeUrl($ws, $item, 'links/' . $link->id . '/revoke'),
            ];
        })->all();
    }

    /**
     * The folders of the workspace the user may put into (for "move" and "copy").
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function folderTree(Request $request, string $workspace)
    {
        return $this->run(function () use ($workspace) {
            $ws = $this->workspace($workspace);
            $access = $this->library()->access();
            $access->authorize($ws, null, Level::VIEW);
            $folders = FileNode::query()->where('workspace_id', $ws->id)->where('node_type', FileNode::TYPE_FOLDER)
                ->whereNull('trashed_at')->orderBy('name')->get();
            $byParent = $folders->groupBy(function (FileNode $f) {
                return strval($f->parent_id ?? 0);
            });
            $list = [];
            if ($access->can($ws, null, Level::UPLOAD)) {
                $list[] = ['id' => null, 'name' => '/', 'depth' => 0];
            }
            $walk = function ($parentKey, $depth, $prefix) use (&$walk, &$list, $byParent, $access, $ws) {
                foreach ($byParent->get($parentKey, collect()) as $folder) {
                    if (!$access->can($ws, $folder, Level::VIEW)) {
                        continue;
                    }
                    if ($access->can($ws, $folder, Level::UPLOAD)) {
                        $list[] = ['id' => $folder->id, 'name' => $prefix . $folder->name, 'depth' => $depth];
                    }
                    $walk(strval($folder->id), $depth + 1, $prefix . $folder->name . '/');
                }
            };
            $walk('0', 1, '/');
            return ['folders' => $list];
        });
    }

    // ================================================================ download

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function download(Request $request, string $workspace, string $node)
    {
        try {
            $ws = $this->workspace($workspace);
            $item = $this->library()->findNode($ws, intval($node));
            Governance::assertDownload($this->library()->access(), $ws, $item);
            if ($item->isFolder()) {
                return $this->zipResponse($ws, [$item], $item->name . '.zip');
            }
            $versionId = $request->get('version');
            $version = $this->library()->version($ws, $item, isset($versionId) ? intval($versionId) : null);
        } catch (FileServerException $e) {
            abort($e->status(), $e->getMessage());
        }
        Audit::log(Audit::DOWNLOAD, $ws, $item, $this->library()->access()->userId(), ['version' => $version->version_no]);
        $name = $item->name;
        if ($version->id !== $item->current_version_id) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $base = $ext === '' ? $name : mb_substr($name, 0, mb_strlen($name) - mb_strlen($ext) - 1);
            $name = $base . '_v' . $version->version_no . ($ext === '' ? '' : '.' . $ext);
        }
        return $this->stream($version->path, $name, $version->mime_type ?: 'application/octet-stream', false);
    }

    /**
     * The bytes to show in the page: the file itself when it is safe to show,
     * or the PDF made of an Office file (rendition=pdf).
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function content(Request $request, string $workspace, string $node)
    {
        try {
            $ws = $this->workspace($workspace);
            $item = $this->library()->findNode($ws, intval($node));
            $versionId = $request->get('version');
            $version = $this->library()->version($ws, $item, isset($versionId) ? intval($versionId) : null);
        } catch (FileServerException $e) {
            abort($e->status(), $e->getMessage());
        }
        $access = $this->library()->access();
        $canDownload = Governance::canDownload($access, $ws, $item);
        if ($request->get('rendition') === 'pdf') {
            if (!isset($version->preview_path)) {
                abort(404);
            }
            if (!$canDownload) {
                Audit::log(Audit::VIEW, $ws, $item, $access->userId(), ['version' => $version->version_no, 'view_only' => 'pdf']);
            }
            return $this->stream($version->preview_path, pathinfo($item->name, PATHINFO_FILENAME) . '.pdf', 'application/pdf', true);
        }
        // who may not download: never the bytes of the file (ViewOnly)
        if (!$canDownload) {
            Audit::log(Audit::VIEW, $ws, $item, $access->userId(), ['version' => $version->version_no, 'view_only' => 'content']);
            return ViewOnly::response($version, $item->name);
        }
        if (!Preview::isInlineSafe($version->mime_type)) {
            // html, svg and the others are only downloaded: they could run scripts
            // in the site. A download: those who may download only, and written
            // in the audit log (as the download page)
            Audit::log(Audit::DOWNLOAD, $ws, $item, $access->userId(), ['version' => $version->version_no, 'content' => true]);
            return $this->stream($version->path, $item->name, 'application/octet-stream', false);
        }
        return $this->stream($version->path, $item->name, strval($version->mime_type), true);
    }

    /**
     * The preview of an Office file opened (ISS01704): its PDF is made now by
     * the converter when it is not made yet (one request makes it; the page
     * asks again until it is there). For those who may see the file.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function previewNow(Request $request, string $workspace, string $node)
    {
        return $this->nodeAction($workspace, $node, function (FileWorkspace $ws, FileNode $item) {
            $version = $this->library()->version($ws, $item);
            $status = Preview::makeOnView($version);
            return [
                'status' => $status,
                'pdf_url' => $status === FileNodeVersion::RENDITION_DONE && isset($version->preview_path) ? $this->nodeUrl($ws, $item, 'content') . '?rendition=pdf' : null,
            ];
        });
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function thumbnail(Request $request, string $workspace, string $node)
    {
        try {
            $ws = $this->workspace($workspace);
            $item = $this->library()->findNode($ws, intval($node));
            $version = $this->library()->version($ws, $item);
        } catch (FileServerException $e) {
            abort($e->status());
        }
        if (!isset($version->thumbnail_path)) {
            abort(404);
        }
        $png = str_ends_with($version->thumbnail_path, '.png');
        return $this->stream($version->thumbnail_path, $png ? 'thumbnail.png' : 'thumbnail.jpg', $png ? 'image/png' : 'image/jpeg', true)->setMaxAge(86400)->setPrivate();
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function downloadZip(Request $request, string $workspace)
    {
        try {
            $ws = $this->workspace($workspace);
            $ids = array_map('intval', (array)$request->input('ids', []));
            $nodes = [];
            foreach ($ids as $id) {
                $node = $this->library()->findNode($ws, $id);
                Governance::assertDownload($this->library()->access(), $ws, $node);
                $nodes[] = $node;
            }
            if (count($nodes) === 0) {
                throw FileServerException::notFound();
            }
        } catch (FileServerException $e) {
            abort($e->status(), $e->getMessage());
        }
        return $this->zipResponse($ws, $nodes, $ws->name . '.zip');
    }

    /**
     * @param FileNode[] $nodes
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function zipResponse(FileWorkspace $ws, array $nodes, string $name)
    {
        try {
            $path = $this->library()->zip($ws, $nodes);
        } catch (FileServerException $e) {
            abort($e->status(), $e->getMessage());
        }
        foreach ($nodes as $node) {
            Audit::log(Audit::ZIP, $ws, $node, $this->library()->access()->userId());
        }
        return response()->download($path, $name, ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }

    /**
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    protected function stream(string $path, string $name, string $mime, bool $inline)
    {
        $disk = Library::disk();
        if (!$disk->exists($path)) {
            abort(404);
        }
        $fallback = preg_replace('/[^\x20-\x7E]|[\\\\"%\/]/', '_', $name) ?: 'file';
        $disposition = HeaderUtils::makeDisposition($inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT, $name, $fallback);
        $headers = [
            'Content-Type' => $mime,
            'Content-Disposition' => $disposition,
            'X-Content-Type-Options' => 'nosniff',
        ];
        if ($inline) {
            $headers['Content-Security-Policy'] = "sandbox; default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'";
        }
        $size = $disk->size($path);
        if ($size > 0) {
            $headers['Content-Length'] = strval($size);
        }
        return response()->stream(function () use ($disk, $path) {
            $stream = $disk->readStream($path);
            if ($stream) {
                fpassthru($stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }, 200, $headers);
    }

    // ================================================================ helpers

    /**
     * Run an operation and answer JSON.
     *
     * @param \Closure(): (array<string, mixed>|\Symfony\Component\HttpFoundation\Response) $callback
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function run(\Closure $callback)
    {
        try {
            $result = $callback();
            if ($result instanceof \Symfony\Component\HttpFoundation\Response) {
                return $result;
            }
            return response()->json(['result' => true] + $result);
        } catch (FileServerException $e) {
            return response()->json(['result' => false, 'message' => $e->getMessage()], $e->status());
        }
    }

    /**
     * @param \Closure(FileWorkspace, FileNode): array<string, mixed> $callback
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function nodeAction(string $workspace, string $node, \Closure $callback)
    {
        return $this->run(function () use ($workspace, $node, $callback) {
            $ws = $this->workspace($workspace);
            $item = $this->library()->findNode($ws, intval($node));
            return $callback($ws, $item);
        });
    }

    protected function workspace(int|string $id): FileWorkspace
    {
        $workspace = FileWorkspace::find(intval($id));
        if (!isset($workspace)) {
            throw FileServerException::notFound();
        }
        return $workspace;
    }

    protected function parentFolder(FileWorkspace $ws, mixed $id): ?FileNode
    {
        if ($id === null || $id === '' || $id === 'null' || intval($id) === 0) {
            return null;
        }
        $folder = $this->library()->findNode($ws, intval($id));
        if (!$folder->isFolder()) {
            throw FileServerException::notFound();
        }
        return $folder;
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function deny(?FileServerException $e = null)
    {
        if (isset($e) && $e->status() === 404) {
            abort(404);
        }
        \ExmentAdminCore\Admin\Auth\Permission::error();
        return response('');
    }

    protected function url(FileWorkspace $ws, int|string ...$parts): string
    {
        return admin_urls('file_server', $ws->id, ...$parts);
    }

    /**
     * The page of a folder (its list), a file or the top: at its path (ISS02815),
     * or with numbers when the name of the workspace cannot be in an address.
     */
    protected function pageUrl(FileWorkspace $ws, ?FileNode $node = null): string
    {
        return (new Paths($this->library()->access()))->url($ws, $node) ?? Paths::numberUrl($ws, $node);
    }

    protected function nodeUrl(FileWorkspace $ws, FileNode $node, ?string $action = null): string
    {
        $url = $this->url($ws, 'nodes', $node->id);
        return isset($action) ? $url . '/' . $action : $url;
    }

    /**
     * @return array<int, array{name: string, url: string}>
     */
    protected function breadcrumbs(FileWorkspace $ws, ?FileNode $node, ?\Exceedone\Exment\Services\FileServer\Access $access = null): array
    {
        $paths = new Paths($access ?? $this->library()->access());
        $list = [['name' => $ws->name, 'url' => $paths->url($ws) ?? $this->url($ws)]];
        if (!isset($node)) {
            return $list;
        }
        foreach ($node->ancestorsAndSelf() as $item) {
            // a folder above that the user may not see: not its name (ISS00433)
            if (isset($access) && !$access->can($ws, $item, Level::VIEW)) {
                $list[] = ['name' => strval(exmtrans('file_server.path_hidden')), 'url' => null];
                continue;
            }
            $list[] = ['name' => $item->name, 'url' => $paths->url($ws, $item) ?? Paths::numberUrl($ws, $item)];
        }
        return $list;
    }

    /**
     * @param iterable<FileNode> $nodes
     * @param \Illuminate\Support\Collection<int, FileMetadataField>|null $fields
     * @return array<int, array<string, mixed>>
     */
    protected function nodeRows(iterable $nodes, ?FileWorkspace $ws = null, ?\Illuminate\Support\Collection $fields = null): array
    {
        $rows = [];
        $paths = new Paths($this->library()->access());
        foreach ($nodes as $node) {
            $workspace = $ws ?? $node->workspace;
            if (!isset($workspace)) {
                continue;
            }
            $version = $node->isFile() ? $node->current_version : null;
            $path = $paths->url($workspace, $node);
            $rows[] = [
                'id' => $node->id,
                'name' => $node->name,
                'is_folder' => $node->isFolder(),
                'size' => $node->size,
                'icon' => $this->icon($node),
                'kind' => $node->isFile() ? Preview::kind($node->mime_type, $node->name) : 'folder',
                'updated_at' => $node->updated_at,
                'updated_user' => $this->userName($node->updated_user_id),
                'workspace' => $workspace->name,
                'url' => $path ?? Paths::numberUrl($workspace, $node),
                'link' => $paths->link($workspace, $node, $path),
                'detail_url' => $this->nodeUrl($workspace, $node),
                'download_url' => $this->nodeUrl($workspace, $node, 'download'),
                'thumbnail_url' => isset($version) && isset($version->thumbnail_path) ? $this->nodeUrl($workspace, $node, 'thumbnail') . '?v=' . $version->id : null,
                'checked_out' => isset($node->checked_out_user_id),
                'unique_permissions' => $node->has_unique_permissions,
                'approval_status' => $node->approval_status,
                'legal_hold' => $node->legal_hold,
                'version_no' => isset($version) ? $version->version_no : null,
                'meta' => isset($fields) ? FileSearch::listValues($node, $fields) : [],
            ];
        }
        return $rows;
    }

    /**
     * The columns of a workspace for the views (with the values of a node).
     *
     * @param \Illuminate\Support\Collection<int, FileMetadataField> $fields
     * @param array<string, mixed>|null $values
     * @return array<int, array<string, mixed>>
     */
    protected function fieldRows(\Illuminate\Support\Collection $fields, ?array $values = null): array
    {
        return $fields->map(function (FileMetadataField $field) use ($values) {
            $value = isset($values) ? array_get($values, $field->name) : null;
            return [
                'name' => $field->name,
                'label' => $field->label,
                'field_type' => $field->field_type,
                'options' => strval($field->options),
                'choices' => $field->choices(),
                'required' => $field->required,
                'default_value' => $field->default_value,
                'show_in_list' => $field->show_in_list,
                'groupable' => $field->hasChoices() || $field->field_type === FileMetadataField::TYPE_BOOLEAN,
                'value' => $value,
                'display' => Metadata::display($field, $value),
            ];
        })->all();
    }

    protected function icon(FileNode $node): string
    {
        if ($node->isFolder()) {
            return 'fa-folder';
        }
        $ext = $node->extension();
        $map = [
            'pdf' => 'fa-file-pdf-o',
            'doc' => 'fa-file-word-o', 'docx' => 'fa-file-word-o', 'odt' => 'fa-file-word-o', 'rtf' => 'fa-file-word-o',
            'xls' => 'fa-file-excel-o', 'xlsx' => 'fa-file-excel-o', 'ods' => 'fa-file-excel-o', 'csv' => 'fa-file-excel-o',
            'ppt' => 'fa-file-powerpoint-o', 'pptx' => 'fa-file-powerpoint-o', 'odp' => 'fa-file-powerpoint-o',
            'zip' => 'fa-file-archive-o', 'lzh' => 'fa-file-archive-o', '7z' => 'fa-file-archive-o', 'gz' => 'fa-file-archive-o',
        ];
        if (isset($map[$ext])) {
            return $map[$ext];
        }
        switch (Preview::kind($node->mime_type, $node->name)) {
            case Preview::KIND_IMAGE:
                return 'fa-file-image-o';
            case Preview::KIND_VIDEO:
                return 'fa-file-video-o';
            case Preview::KIND_AUDIO:
                return 'fa-file-audio-o';
            case Preview::KIND_TEXT:
                return 'fa-file-text-o';
        }
        return 'fa-file-o';
    }

    /** @var array<int, string> */
    protected array $userNames = [];

    protected function userName(mixed $userId): string
    {
        if (!isset($userId)) {
            return '';
        }
        $userId = intval($userId);
        if (!array_key_exists($userId, $this->userNames)) {
            $user = getModelName(SystemTableName::USER)::find($userId);
            $this->userNames[$userId] = isset($user) ? strval($user->getLabel()) : '';
        }
        return $this->userNames[$userId];
    }

    /**
     * @param iterable<FilePermission> $permissions
     * @return array<int, array<string, mixed>>
     */
    protected function permissionRows(iterable $permissions): array
    {
        $rows = [];
        foreach ($permissions as $p) {
            $name = '';
            if ($p->target_type === FilePermission::TARGET_USER) {
                $name = $this->userName($p->target_id);
            } else {
                $org = getModelName(SystemTableName::ORGANIZATION)::find($p->target_id);
                $name = isset($org) ? strval($org->getLabel()) : '';
            }
            $rows[] = [
                'target_type' => $p->target_type,
                'target_id' => $p->target_id,
                'name' => $name,
                'level' => $p->level,
                'level_label' => Level::label($p->level),
            ];
        }
        return $rows;
    }

    /**
     * Where the editor of the permissions finds the users and organizations
     * (searched, a page at a time: a site may have more than can be listed),
     * and the levels.
     *
     * @return array<string, mixed>
     */
    protected function pickerData(?FileWorkspace $ws = null, ?FileNode $node = null): array
    {
        $query = array_filter(['workspace' => optional($ws)->id, 'node' => optional($node)->id]);
        return [
            'picker_url' => admin_url('file_server/picker') . (count($query) > 0 ? '?' . http_build_query($query) : ''),
            'picker_orgs' => boolval(\Exceedone\Exment\Model\System::organization_available()),
            'levels' => Level::options(),
        ];
    }

    /** The users or organizations of one page of the picker. */
    public const PICKER_PAGE = 20;

    /** The columns the picker searches: the names and codes (ISS00433). */
    public const PICKER_COLUMNS = [
        SystemTableName::USER => ['user_name', 'user_code'],
        SystemTableName::ORGANIZATION => ['organization_name', 'organization_code'],
    ];

    /**
     * The users or organizations for the editor of the permissions (select2):
     * ?type=user|organization&q=words&page=n. For who may make a workspace,
     * or manage the workspace (or the node) given.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function picker(Request $request)
    {
        $access = $this->library()->access();
        $allowed = $access->canCreateWorkspace();
        if (!$allowed && $request->filled('workspace')) {
            $ws = FileWorkspace::find(intval($request->query('workspace')));
            $node = $request->filled('node') ? FileNode::query()->where('workspace_id', optional($ws)->id)->find(intval($request->query('node'))) : null;
            $allowed = isset($ws) && (!$request->filled('node') || isset($node)) && $access->level($ws, $node) >= Level::MANAGE;
        }
        if (!$allowed) {
            return response()->json(['results' => [], 'pagination' => ['more' => false]], 403);
        }
        $organization = $request->query('type') === FilePermission::TARGET_ORGANIZATION;
        if ($organization && !\Exceedone\Exment\Model\System::organization_available()) {
            return response()->json(['results' => [], 'pagination' => ['more' => false]]);
        }
        $tableName = $organization ? SystemTableName::ORGANIZATION : SystemTableName::USER;
        $q = $request->query('q', '');
        $words = is_string($q) ? trim($q) : '';
        $page = max(1, intval(is_string($request->query('page')) ? $request->query('page') : 1));
        $modelName = getModelName($tableName);
        // the users the user may see (the permissions of the table), as the list was
        $query = $modelName::query();
        if ($words !== '') {
            // by the names and codes only (not by the e-mail or the other columns)
            $columns = [];
            foreach (static::PICKER_COLUMNS[$tableName] as $columnName) {
                $column = \Exceedone\Exment\Model\CustomColumn::getEloquent($columnName, $tableName);
                if (isset($column)) {
                    $columns[] = $column;
                }
            }
            if (count($columns) === 0) {
                return response()->json(['results' => [], 'pagination' => ['more' => false]]);
            }
            (new $modelName())->setSearchQueryOrWhere($query, $words, ['searchColumns' => $columns, 'isLike' => true]);
        }
        $paginator = $query->orderBy('id')->paginate(static::PICKER_PAGE, ['*'], 'page', $page);
        $results = [];
        foreach ($paginator->items() as $value) {
            $results[] = ['id' => intval($value->id), 'text' => strval($value->getLabel())];
        }
        return response()->json(['results' => $results, 'pagination' => ['more' => $paginator->hasMorePages()]]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function workspaceInput(Request $request): array
    {
        $quotaGb = $request->input('quota_gb');
        return [
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'quota_bytes' => ($quotaGb === null || $quotaGb === '') ? null : intval(round(floatval($quotaGb) * 1024 * 1024 * 1024)),
            'trash_days' => $request->input('trash_days', 30),
            'version_limit' => $request->input('version_limit', 50),
        ];
    }

    /**
     * @return array<int, array{target_type: string, target_id: int, level: int}>
     */
    protected function permissionInput(Request $request): array
    {
        $entries = [];
        foreach ((array)$request->input('permissions', []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $entries[] = [
                'target_type' => strval(array_get($entry, 'target_type')),
                'target_id' => intval(array_get($entry, 'target_id')),
                'level' => intval(array_get($entry, 'level')),
            ];
        }
        return $entries;
    }
}
