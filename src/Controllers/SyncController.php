<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\SyncConnection;
use Exceedone\Exment\Model\SyncLink;
use Exceedone\Exment\Model\SyncMapping;
use Exceedone\Exment\Model\SyncRun;
use Exceedone\Exment\Services\Sync\SyncConnectors;
use Exceedone\Exment\Services\Sync\SyncEngine;
use Exceedone\Exment\Services\Sync\SyncSecrets;
use Exceedone\Exment\Services\Sync\RemoteField;
use ExmentAdminCore\Admin\Layout\Content;
use Illuminate\Http\Request;

/**
 * The page "Sync" of the data sync (ISS00113): the connections, the mappings, the runs. The
 * system administrators only, while the feature is used (Features, "data_sync"). Every change and
 * run is in the operation log (the posts of this page); a secret is never put on the page again.
 */
class SyncController extends AdminControllerBase
{
    public function __construct()
    {
        $this->setPageInfo(exmtrans('sync.header'), exmtrans('sync.header'), exmtrans('sync.description'), 'fa-refresh');
    }

    public function index(Request $request, Content $content): Content
    {
        static::guard();
        $this->AdminContent($content);
        $connections = SyncConnection::query()->orderBy('id')->get();
        $mappings = SyncMapping::query()->orderBy('id')->get()->groupBy('sync_connection_id');
        $lastRuns = SyncRun::query()->whereIn('id', SyncRun::query()->selectRaw('max(id)')->groupBy('sync_mapping_id'))->get()->keyBy('sync_mapping_id');
        $content->row(view('exment::sync.index', [
            'connections' => $connections,
            'mappings' => $mappings,
            'lastRuns' => $lastRuns,
            'connectors' => SyncConnectors::options(),
            'appKeyWarning' => SyncSecrets::usesAppKey(),
            'tables' => static::tables(),
        ]));
        return $content;
    }

    public function connection(Request $request, Content $content, ?string $id = null): Content
    {
        static::guard();
        $this->AdminContent($content);
        $connection = isset($id) ? SyncConnection::query()->findOrFail($id) : new SyncConnection(['connector' => strval($request->get('connector'))]);
        $connector = SyncConnectors::get($connection->connector);
        if (!isset($connector)) {
            abort(404);
        }
        $content->row(view('exment::sync.connection', [
            'connection' => $connection,
            'connector' => $connector,
            'appKeyWarning' => SyncSecrets::usesAppKey(),
            'old' => $request->old(),
        ]));
        return $content;
    }

    public function saveConnection(Request $request, ?string $id = null): \Illuminate\Http\RedirectResponse
    {
        static::guard();
        $connection = isset($id) ? SyncConnection::query()->findOrFail($id) : new SyncConnection(['connector' => strval($request->get('connector'))]);
        $connector = SyncConnectors::get($connection->connector);
        if (!isset($connector)) {
            abort(404);
        }
        $name = trim(strval($request->get('name')));
        if ($name === '' || mb_strlen($name) > 100) {
            admin_toastr(exmtrans('sync.message.name_required'), 'error');
            return back()->withInput($request->except(array_map(fn ($field) => 'sync_' . $field . '_secret', array_keys($connector->secretFields()))));
        }
        $connection->name = $name;
        $settings = [];
        foreach (array_keys($connector->settingFields()) as $field) {
            $settings[$field] = trim(strval($request->get('settings_' . $field)));
        }
        $connection->settings = $settings;
        // a secret: a new one replaces it, an empty box keeps it, the switch removes it
        foreach (array_keys($connector->secretFields()) as $field) {
            $new = strval($request->get('sync_' . $field . '_secret'));
            if (trim($new) !== '') {
                $connection->setSecret($field, trim($new));
            } elseif (boolval($request->get('clear_' . $field))) {
                $connection->setSecret($field, null);
            }
        }
        $connection->enabled = boolval($request->get('enabled'));
        $connection->created_user_id = $connection->created_user_id ?? static::userId();
        $connection->updated_user_id = static::userId();
        $connection->save();
        admin_toastr(trans('admin.save_succeeded'));
        return redirect(admin_url('sync'));
    }

    public function testConnection(string $id): \Illuminate\Http\RedirectResponse
    {
        static::guard();
        /** @var SyncConnection $connection */
        $connection = SyncConnection::query()->findOrFail($id);
        $connector = $connection->connectorInstance();
        $error = isset($connector) ? $connector->test($connection) : strval(exmtrans('sync.message.not_ready'));
        if (isset($error)) {
            admin_toastr(exmtrans('sync.message.test_failed', ['message' => $error]), 'error');
        } else {
            admin_toastr(exmtrans('sync.message.test_succeeded'));
        }
        return redirect(admin_url('sync'));
    }

    public function mapping(Request $request, Content $content, ?string $id = null): Content
    {
        static::guard();
        $this->AdminContent($content);
        $mapping = isset($id) ? SyncMapping::query()->findOrFail($id) : new SyncMapping([
            'sync_connection_id' => intval($request->get('connection')),
            'interval_minutes' => 30,
            'conflict_rule' => SyncMapping::CONFLICT_REMOTE,
            'delete_rule' => SyncMapping::DELETE_MARK,
        ]);
        /** @var SyncConnection $connection */
        $connection = SyncConnection::query()->findOrFail($mapping->sync_connection_id);
        $connector = $connection->connectorInstance();
        if (!isset($connector)) {
            abort(404);
        }
        $objects = [];
        $fields = [];
        $error = null;
        try {
            $objects = $connector->objects($connection);
            $object = strval($request->get('remote_object', $mapping->remote_object));
            if ($object !== '') {
                $fields = $connector->fields($connection, $object);
            }
        } catch (\Throwable $e) {
            $error = $e instanceof \Exceedone\Exment\Services\Sync\SyncException ? $e->getMessage() : get_class($e);
        }
        $table = CustomTable::getEloquent(intval($request->get('custom_table_id', $mapping->custom_table_id)));
        $connectorFields = [];
        if ($connector instanceof \Exceedone\Exment\Services\Sync\SyncMappingFields && strval($request->get('remote_object', $mapping->remote_object)) !== '') {
            try {
                $connectorFields = $connector->mappingFields($connection, strval($request->get('remote_object', $mapping->remote_object)), $mapping);
            } catch (\Throwable $e) {
                $error = $error ?? ($e instanceof \Exceedone\Exment\Services\Sync\SyncException ? $e->getMessage() : get_class($e));
            }
        }
        $content->row(view('exment::sync.mapping', [
            'connectorFields' => $connectorFields,
            'writesItself' => $connector instanceof \Exceedone\Exment\Services\Sync\SyncWriter,
            'mapping' => $mapping,
            'connection' => $connection,
            'objects' => $objects,
            'fields' => $fields,
            'error' => $error,
            'tables' => static::tables(),
            'table' => $table,
            'columns' => isset($table) ? $table->custom_columns_cache->pluck('column_view_name', 'column_name')->all() : [],
            'suggested' => isset($table) ? static::suggest($fields, $table) : [],
            'remoteObject' => strval($request->get('remote_object', $mapping->remote_object)),
        ]));
        return $content;
    }

    public function saveMapping(Request $request, ?string $id = null): \Illuminate\Http\RedirectResponse
    {
        static::guard();
        $mapping = isset($id) ? SyncMapping::query()->findOrFail($id) : new SyncMapping(['sync_connection_id' => intval($request->get('sync_connection_id'))]);
        /** @var SyncConnection $connection */
        $connection = SyncConnection::query()->findOrFail($mapping->sync_connection_id);
        $table = CustomTable::getEloquent(intval($request->get('custom_table_id')));
        $object = trim(strval($request->get('remote_object')));
        if (!isset($table) || $object === '' || trim(strval($request->get('name'))) === '') {
            admin_toastr(exmtrans('sync.message.mapping_required'), 'error');
            return back()->withInput();
        }
        // the settings of the connector on the mapping
        $connectorValues = [];
        $connector = $connection->connectorInstance();
        if ($connector instanceof \Exceedone\Exment\Services\Sync\SyncMappingFields) {
            foreach (array_keys($connector->mappingFields($connection, $object, $mapping)) as $field) {
                $connectorValues[$field] = mb_substr(trim(strval($request->get('connector_' . $field))), 0, 1000);
            }
            $error = $connector->checkMappingFields($connection, $object, $connectorValues);
            if (isset($error)) {
                admin_toastr($error, 'error');
                return back()->withInput();
            }
        }
        $map = [];
        foreach ((array)$request->get('field_map', []) as $remote => $column) {
            if (!is_nullorempty($column) && !is_nullorempty($remote)) {
                $map[] = ['remote' => strval($remote), 'column' => strval($column)];
            }
        }
        $mapping->fill([
            'name' => mb_substr(trim(strval($request->get('name'))), 0, 100),
            'remote_object' => $object,
            'remote_label' => mb_substr(strval($request->get('remote_label')), 0, 255),
            'custom_table_id' => $table->id,
            'direction' => SyncMapping::DIRECTION_IMPORT,
            'interval_minutes' => in_array(intval($request->get('interval_minutes')), SyncMapping::INTERVALS, true) ? intval($request->get('interval_minutes')) : 30,
            'conflict_rule' => in_array($request->get('conflict_rule'), SyncMapping::CONFLICT_RULES, true) ? $request->get('conflict_rule') : SyncMapping::CONFLICT_REMOTE,
            'delete_rule' => in_array($request->get('delete_rule'), SyncMapping::DELETE_RULES, true) ? $request->get('delete_rule') : SyncMapping::DELETE_MARK,
            'field_map' => $map,
            'options' => [
                'keep_times' => boolval($request->get('keep_times')),
                'check_deletes' => boolval($request->get('check_deletes')),
                'connector' => $connectorValues,
            ],
            'enabled' => boolval($request->get('enabled')),
        ]);
        $mapping->save();
        admin_toastr(trans('admin.save_succeeded'));
        return redirect(admin_url('sync'));
    }

    public function run(Request $request, string $id): \Illuminate\Http\RedirectResponse
    {
        static::guard();
        /** @var SyncMapping $mapping */
        $mapping = SyncMapping::query()->findOrFail($id);
        $dry = boolval($request->get('dry'));
        if (!$dry && SyncRun::query()->where('sync_mapping_id', $mapping->id)->where('status', SyncRun::STATUS_RUNNING)->exists()) {
            admin_toastr(exmtrans('sync.message.already_running'), 'error');
            return redirect(admin_url('sync'));
        }
        $run = SyncEngine::start($mapping, $dry, 'manual', static::userId());
        return redirect()->to(strval(admin_urls('sync', 'runs', $run->id)));
    }

    public function showRun(Content $content, string $id): Content
    {
        static::guard();
        $this->AdminContent($content);
        /** @var SyncRun $run */
        $run = SyncRun::query()->findOrFail($id);
        $content->row(view('exment::sync.run', [
            'run' => $run,
            'mapping' => SyncMapping::query()->find($run->sync_mapping_id),
            'runErrors' => $run->errors()->orderBy('id')->limit(500)->get(),
        ]));
        return $content;
    }

    /**
     * The old URL and the new one of each item (the table of a move).
     */
    public function links(string $id): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        static::guard();
        /** @var SyncMapping $mapping */
        $mapping = SyncMapping::query()->findOrFail($id);
        $table = $mapping->customTable();
        return response()->streamDownload(function () use ($mapping, $table) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['remote_id', 'remote_url', 'exment_id', 'exment_url', 'remote_deleted_at']);
            SyncLink::query()->where('sync_mapping_id', $mapping->id)->orderBy('id')->chunk(1000, function ($links) use ($out, $table) {
                foreach ($links as $link) {
                    /** @var SyncLink $link */
                    fputcsv($out, array_map(fn ($v) => AuditReportController::csvCell(strval($v)), [
                        $link->remote_id,
                        $link->remote_url,
                        $link->custom_value_id,
                        isset($table, $link->custom_value_id) ? admin_urls('data', $table->table_name, $link->custom_value_id) : '',
                        optional($link->remote_deleted_at)->format('Y-m-d H:i:s'),
                    ]));
                }
            });
            fclose($out);
        }, 'sync-links-' . $mapping->id . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * The column of each field: one of the same name or label.
     *
     * @param array<int, RemoteField> $fields
     * @return array<string, string> field code => column name
     */
    public static function suggest(array $fields, CustomTable $table): array
    {
        $suggested = [];
        foreach ($fields as $field) {
            foreach ($table->custom_columns_cache as $column) {
                if (in_array(mb_strtolower($field->code), [mb_strtolower($column->column_name), mb_strtolower($column->column_view_name)], true)
                    || mb_strtolower($field->label) === mb_strtolower($column->column_view_name)) {
                    $suggested[$field->code] = $column->column_name;
                    break;
                }
            }
        }
        return $suggested;
    }

    /**
     * @return array<int|string, string>
     */
    protected static function tables(): array
    {
        return CustomTable::query()->orderBy('order')->orderBy('id')->get()->pluck('table_view_name', 'id')->all();
    }

    protected static function userId(): ?int
    {
        $id = \Exment::getUserId();
        return is_null($id) ? null : intval($id);
    }

    /**
     * The system administrators only, while the feature is used.
     */
    protected static function guard(): void
    {
        $user = \Exment::user();
        if (!isset($user) || !$user->isAdministrator() || !SyncEngine::enabled()) {
            abort(403);
        }
    }
}
