<?php

namespace Exceedone\Exment\Controllers;

use Carbon\Carbon;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\DataArchive;
use Exceedone\Exment\Model\DataArchiveEvent;
use Exceedone\Exment\Services\DataArchive\ArchiveException;
use Exceedone\Exment\Services\DataArchive\ArchiveStore;
use Exceedone\Exment\Services\DataArchive\DataArchiver;
use Exceedone\Exment\Services\DataArchive\Sources;
use ExmentAdminCore\Admin\Layout\Content;
use Illuminate\Http\Request;

/**
 * The data archive (ISS02970): the sources (with their policies, a preview, then the archive),
 * the archives (restore, trash, legal hold), the trash of the archives and the record.
 *
 * The system administrators do everything; the role groups with "Audit" see the lists and the record.
 */
class DataArchiveController extends AdminControllerBase
{
    public const TABS = ['sources', 'archives', 'trash', 'events'];

    public const PAGE_ROWS = 100;

    public function __construct()
    {
        $this->setPageInfo(exmtrans('data_archive.header'), exmtrans('data_archive.header'), exmtrans('data_archive.description'), 'fa-archive');
    }

    /**
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        if (!Permission::canAudit()) {
            abort(403);
        }
        $this->AdminContent($content);
        $tab = in_array($request->get('tab'), static::TABS, true) ? strval($request->get('tab')) : 'sources';
        $ready = DataArchive::tableExists();

        $data = [
            'tab' => $tab, 'ready' => $ready, 'isSystem' => $this->isSystem(),
            'url' => admin_url('data_archive'), 'driver' => ArchiveStore::driver(),
            'trashDays' => DataArchiver::DEFAULT_TRASH_DAYS, 'noticeDays' => DataArchiver::NOTICE_DAYS,
        ];
        if ($ready && $tab === 'sources') {
            $data['sources'] = $this->sources();
            $data['preview'] = $this->previewOf($request);
        } elseif ($ready && in_array($tab, ['archives', 'trash'], true)) {
            $query = DataArchive::query()->orderByDesc('id');
            $tab === 'trash' ? $query->whereNotNull('deleted_at') : $query->whereNull('deleted_at');
            $data['archives'] = $query->limit(500)->get();
            $data['userName'] = $this->userNames($data['archives']->pluck('created_user_id')->all());
            $restoreId = intval($request->get('restore'));
            $data['restore'] = null;
            if ($restoreId > 0 && $tab === 'archives') {
                $archive = DataArchive::find($restoreId);
                if (isset($archive)) {
                    try {
                        $data['restore'] = ['archive' => $archive, 'preview' => DataArchiver::restorePreview($archive)];
                    } catch (ArchiveException $e) {
                        $data['restore'] = ['archive' => $archive, 'error' => $e->text()];
                    } catch (\Throwable $e) {
                        \Log::warning('[Exment] data archive: the restore preview: ' . $e->getMessage());
                        $data['restore'] = ['archive' => $archive, 'error' => exmtrans('data_archive.errors.missing')];
                    }
                }
            }
        } elseif ($ready && $tab === 'events') {
            $events = DataArchiveEvent::query()->orderByDesc('id');
            $action = $request->get('action');
            if (is_string($action) && preg_match('/^[a-z_]{1,16}$/', $action) === 1) {
                $events->where('action', $action);
            }
            $source = $request->get('source');
            if (is_string($source) && isset(Sources::all()[$source])) {
                $events->where('source', $source);
            }
            $data['events'] = $events->limit(static::PAGE_ROWS)->get();
            $data['userName'] = $this->userNames($data['events']->pluck('user_id')->all());
            $data['filter'] = ['action' => is_string($action) ? $action : null, 'source' => is_string($source) ? $source : null];
            $data['sourceKeys'] = array_keys(Sources::all());
        }
        $content->row(view('exment::data_archive.index', $data));
        return $content;
    }

    /**
     * The sources: the rows, the oldest, the policy.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function sources(): array
    {
        $out = [];
        foreach (Sources::all() as $key => $source) {
            $table = Sources::table($key);
            $row = ['key' => $key, 'table' => $table, 'rows' => null, 'oldest' => null, 'policy' => DataArchiver::policy($key)];
            if (isset($table)) {
                try {
                    $query = Sources::query($key, DataArchiver::now()->addDay());
                    $row['rows'] = $query->count();
                    $oldest = $query->min(strval($source['date']));
                    $row['oldest'] = isset($oldest) ? Carbon::parse($oldest)->format('Y-m-d') : null;
                } catch (\Throwable $e) {
                    $row['rows'] = null;
                }
            }
            $out[$key] = $row;
        }
        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function previewOf(Request $request): ?array
    {
        $source = $request->get('preview');
        if (!is_string($source) || is_null(Sources::get($source))) {
            return null;
        }
        $before = $this->beforeOf($request->get('before'));
        if (!isset($before)) {
            return ['source' => $source, 'error' => exmtrans('data_archive.errors.before')];
        }
        try {
            return DataArchiver::preview($source, $before, \Exment::getUserId());
        } catch (ArchiveException $e) {
            return ['source' => $source, 'error' => $e->text()];
        }
    }

    /**
     * Archive (system administrators), from a preview.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function run(Request $request)
    {
        $this->systemOnly();
        $source = strval($request->get('source'));
        $before = $this->beforeOf($request->get('before'));
        if (is_null(Sources::get($source)) || !isset($before) || !is_numeric($request->get('expected'))) {
            abort(400);
        }
        try {
            $archive = DataArchiver::archive($source, $before, intval($request->get('expected')), \Exment::getUserId());
            admin_toastr(exmtrans('data_archive.archived', ['count' => number_format($archive->deleted_count)]));
            if ($archive->warning) {
                admin_warning(exmtrans('data_archive.header'), strval($archive->message));
            }
            return redirect()->to(admin_url('data_archive') . '?tab=archives');
        } catch (ArchiveException $e) {
            admin_error(exmtrans('data_archive.header'), $e->text());
        } catch (\Throwable $e) {
            \Log::error('[Exment] data archive: ' . $e->getMessage());
            admin_error(exmtrans('data_archive.header'), exmtrans('data_archive.errors.write'));
        }
        return redirect()->to(admin_url('data_archive') . '?tab=sources');
    }

    /**
     * Put the rows of an archive back (system administrators).
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function restore(Request $request, $id)
    {
        $this->systemOnly();
        $archive = DataArchive::findOrFail(intval($id));
        try {
            $count = DataArchiver::restore($archive, \Exment::getUserId());
            admin_toastr(exmtrans('data_archive.restored', ['count' => number_format($count)]));
        } catch (ArchiveException $e) {
            admin_error(exmtrans('data_archive.header'), $e->text());
        } catch (\Throwable $e) {
            \Log::error('[Exment] data archive: the restore: ' . $e->getMessage());
            admin_error(exmtrans('data_archive.header'), exmtrans('data_archive.errors.missing'));
        }
        return redirect()->to(admin_url('data_archive') . '?tab=archives');
    }

    /**
     * Trash, back from the trash, hold, release (system administrators).
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function action(Request $request, $id)
    {
        $this->systemOnly();
        $archive = DataArchive::findOrFail(intval($id));
        $action = strval($request->get('do'));
        $userId = \Exment::getUserId();
        $tab = 'archives';
        try {
            switch ($action) {
                case 'trash':
                    DataArchiver::trash($archive, $userId);
                    break;
                case 'untrash':
                    DataArchiver::untrash($archive, $userId);
                    $tab = 'trash';
                    break;
                case 'hold':
                case 'unhold':
                    DataArchiver::hold($archive, $action === 'hold', $userId);
                    $tab = $archive->inTrash() ? 'trash' : 'archives';
                    break;
                default:
                    abort(400);
            }
            admin_toastr(trans('admin.save_succeeded'));
        } catch (ArchiveException $e) {
            admin_error(exmtrans('data_archive.header'), $e->text());
        }
        return redirect()->to(admin_url('data_archive') . '?tab=' . $tab);
    }

    /**
     * The policies and where the files go (system administrators).
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function policies(Request $request)
    {
        $this->systemOnly();
        $validator = \Validator::make($request->all(), [
            'policies' => 'array',
            'policies.*.older_days' => 'nullable|integer|min:0|max:36500',
            'policies.*.trash_days' => 'nullable|integer|min:1|max:36500',
            'driver' => 'nullable|in:' . implode(',', ArchiveStore::DRIVERS),
        ]);
        if ($validator->fails()) {
            return back()->withInput()->withErrors($validator);
        }
        DataArchiver::savePolicies((array)$request->get('policies', []));
        if ($request->filled('driver')) {
            \Exceedone\Exment\Model\System::data_archive_driver(strval($request->get('driver')));
        }
        \Exceedone\Exment\Model\System::clearCache();
        DataArchiver::event(null, null, 'policy', null, null, \Exment::getUserId());
        admin_toastr(trans('admin.save_succeeded'));
        return redirect()->to(admin_url('data_archive') . '?tab=sources');
    }

    protected function beforeOf($value): ?Carbon
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        try {
            $before = Carbon::createFromFormat('Y-m-d', $value);
            return $before === false ? null : $before->startOfDay();
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function isSystem(): bool
    {
        $user = \Exment::user();
        return isset($user) && boolval($user->hasPermission(Permission::SYSTEM));
    }

    protected function systemOnly(): void
    {
        if (!$this->isSystem()) {
            abort(403);
        }
    }

    /**
     * @param array<int, mixed> $ids
     */
    protected function userNames(array $ids): \Closure
    {
        $ids = array_values(array_unique(array_filter($ids)));
        $users = getModelName(SystemTableName::USER)::withTrashed()->whereIn('id', $ids)->get()->keyBy('id');
        return function ($id) use ($users): string {
            if (is_nullorempty($id)) {
                return exmtrans('data_archive.schedule');
            }
            $user = $users->get($id);
            return isset($user) ? strval(array_get($user->value ?? [], 'user_name')) : '#' . strval($id);
        };
    }
}
