<?php

namespace Exceedone\Exment\Controllers;

use ExmentAdminCore\Admin\Layout\Content;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\StorageState;
use Exceedone\Exment\Exceptions\StorageConversionException;
use Exceedone\Exment\Jobs\StorageConversionJob;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Services\Storage\ConversionAvailability;
use Exceedone\Exment\Services\Storage\CutoverConfirmation;
use Exceedone\Exment\Services\Storage\DrainAttestation;
use Exceedone\Exment\Services\Storage\PhysicalColumnLifecycle;
use Exceedone\Exment\Services\Storage\PhysicalSchemaManager;
use Exceedone\Exment\Services\Storage\PhysicalTableInitializer;
use Exceedone\Exment\Services\Storage\StagingCleanup;
use Exceedone\Exment\Services\Storage\StorageConversionStatus;
use Exceedone\Exment\Services\Storage\StorageTransitions;
use Exceedone\Exment\Services\Storage\TableStorage;
use Illuminate\Http\Request;

/**
 * Hybrid storage management screen (Stage B block 5). System Administrator only.
 *
 * GET pages are read-only (StorageConversionStatus: preflight, state, progress, L5 findings,
 * errors) and work whether or not execution is available. Every POST is an authenticated,
 * CSRF-protected (adminweb middleware) explicit action that calls the existing storage services,
 * which re-check their own preconditions under their own locks; queued work is dispatched as
 * StorageConversionJob. While ConversionAvailability is unavailable (production binding
 * UnavailableConversion) every action is refused before any service call and the page says so.
 * Confirmations and raw-writer attestations are what the administrator submits - never
 * pre-filled as confirmed, never inferred from elapsed time.
 */
class StorageConversionController extends AdminControllerBase
{
    public function __construct()
    {
        $this->setPageInfo(exmtrans('storage_conversion.header'), exmtrans('storage_conversion.header'), exmtrans('storage_conversion.description'), 'fa-database');
    }

    /** System-admin gate, first in every action (see SafetyCheckController::authorizeSystemAdmin). */
    protected function authorizeSystemAdmin(): void
    {
        $user = \Exment::user();
        if (!$user || !$user->hasPermission(Permission::SYSTEM)) {
            abort(403);
        }
    }

    public function index(Request $request, Content $content)
    {
        $this->authorizeSystemAdmin();
        $status = app(StorageConversionStatus::class);
        return $this->AdminContent($content)->body(view('exment::storage-conversion.index', [
            'execution' => $status->execution(),
            'tables' => $status->tables(),
        ]));
    }

    public function show(Request $request, Content $content, $id)
    {
        $this->authorizeSystemAdmin();
        $table = $this->table($id);
        $status = app(StorageConversionStatus::class);
        return $this->AdminContent($content)->body(view('exment::storage-conversion.show', [
            'execution' => $status->execution(),
            'table' => $table,
            'status' => $status->table($table),
            'attestationCodes' => DrainAttestation::REQUIRED,
            'actionBase' => admin_url('storage_conversion/' . $table->id),
        ]));
    }

    // ------------------------------------------------------------------ actions

    public function provision(Request $request, $id)
    {
        return $this->act($id, function (CustomTable $table) {
            $this->dispatchFor($table, StorageConversionJob::OP_PROVISION);
            return 'message_queued';
        });
    }

    public function dualwrite(Request $request, $id)
    {
        return $this->act($id, function (CustomTable $table) {
            app(StorageTransitions::class)->startDualWrite($table, (int)\Exment::getUserId());
            return 'message_done';
        });
    }

    /** dualwrite -> backfilling with the submitted drain attestation, or resume a running backfill. */
    public function backfill(Request $request, $id)
    {
        return $this->act($id, function (CustomTable $table) use ($request) {
            if (TableStorage::fresh($table)->state() === StorageState::DUALWRITE) {
                app(StorageTransitions::class)->startBackfill($table, $this->attestation($request), (int)\Exment::getUserId());
            }
            $this->dispatchFor($table, StorageConversionJob::OP_BACKFILL);
            return 'message_queued';
        });
    }

    /** backfilling -> verifying (backfill complete), or (re)run verification in verifying / physical_read. */
    public function verify(Request $request, $id)
    {
        return $this->act($id, function (CustomTable $table) {
            if (TableStorage::fresh($table)->state() === StorageState::BACKFILLING) {
                app(StorageTransitions::class)->startVerification($table, (int)\Exment::getUserId());
            }
            $this->dispatchFor($table, StorageConversionJob::OP_VERIFY);
            return 'message_queued';
        });
    }

    public function awaitConfirmation(Request $request, $id)
    {
        return $this->act($id, function (CustomTable $table) {
            app(StorageTransitions::class)->awaitConfirmation($table, (int)\Exment::getUserId());
            return 'message_done';
        });
    }

    /** L5 confirmation + raw-writer exclusion attestation -> cutover. */
    public function cutover(Request $request, $id)
    {
        return $this->act($id, function (CustomTable $table) use ($request) {
            if (!$request->boolean('confirm_l5')) {
                throw new StorageConversionException('cutover refused: the L5 report was not confirmed', []);
            }
            $confirmation = new CutoverConfirmation(
                (int)\Exment::getUserId(),
                (int)$request->get('run_id'),
                (int)$request->get('generation'),
                (string)$request->get('report_version'),
                new \DateTimeImmutable()
            );
            app(StorageTransitions::class)->cutover($table, $confirmation, $this->attestation($request), (int)\Exment::getUserId());
            return 'message_done';
        });
    }

    public function cancel(Request $request, $id)
    {
        return $this->act($id, function (CustomTable $table) {
            app(StorageTransitions::class)->cancel($table, (int)\Exment::getUserId());
            return 'message_done';
        });
    }

    public function rollback(Request $request, $id)
    {
        return $this->act($id, function (CustomTable $table) use ($request) {
            app(StorageTransitions::class)->rollback($table, $this->attestation($request), $request->boolean('accept_json_as_source'), (int)\Exment::getUserId());
            return 'message_done';
        });
    }

    /** Staging cleanup after cancellation: typed table name as explicit intent. */
    public function cleanup(Request $request, $id)
    {
        return $this->act($id, function (CustomTable $table) use ($request) {
            app(StagingCleanup::class)->startConfirmed($table, (string)$request->get('confirm_table_name'));
            $this->dispatchFor($table, StorageConversionJob::OP_CLEANUP);
            return 'message_queued';
        });
    }

    /** Queue the bounded automatic index job (physical_read; one index per job; budget required). */
    public function autoIndex(Request $request, $id)
    {
        return $this->act($id, function (CustomTable $table) {
            if (TableStorage::fresh($table)->state() !== StorageState::PHYSICAL_READ) {
                throw new StorageConversionException('automatic indexes refused: the table does not read physical', []);
            }
            if (\Exceedone\Exment\Services\Storage\StorageAutoIndexer::budget() === null) {
                throw new StorageConversionException('automatic indexes are disabled: no configured index budget', []);
            }
            $this->dispatchFor($table, StorageConversionJob::OP_INDEX);
            return 'message_queued';
        });
    }

    /** Drop the (empty) staging columns and return to json - DDL, run synchronously, resumable. */
    public function deprovision(Request $request, $id)
    {
        return $this->act($id, function (CustomTable $table) use ($request) {
            if (!hash_equals((string)$table->table_name, (string)$request->get('confirm_table_name'))) {
                throw new StorageConversionException('deprovision refused: the typed table name does not match', []);
            }
            app(PhysicalSchemaManager::class)->deprovision($table);
            return 'message_done';
        });
    }

    /** Resume the initialization of a table created as physical (retry after a failed DDL step). */
    public function initialize(Request $request, $id)
    {
        return $this->act($id, function (CustomTable $table) {
            app(PhysicalTableInitializer::class)->initialize($table, (int)\Exment::getUserId());
            return 'message_done';
        });
    }

    /** Cancel an initialization (only while the table is empty): drop the empty typed columns, back to JSON. */
    public function cancelInitialize(Request $request, $id)
    {
        return $this->act($id, function (CustomTable $table) {
            app(PhysicalTableInitializer::class)->cancel($table, (int)\Exment::getUserId());
            return 'message_done';
        });
    }

    /** Recovery of an interrupted column step on a table that reads physical: schema = definition (idempotent). */
    public function reconcile(Request $request, $id)
    {
        return $this->act($id, function (CustomTable $table) {
            app(PhysicalColumnLifecycle::class)->reconcile($table, (int)\Exment::getUserId());
            return 'message_done';
        });
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Run one administrative action: admin gate, execution boundary, refusal reporting.
     *
     * @param mixed $id
     * @return \Illuminate\Http\RedirectResponse
     */
    protected function act($id, \Closure $action)
    {
        $this->authorizeSystemAdmin();
        $table = $this->table($id);
        $back = redirect(admin_url('storage_conversion/' . $table->id));
        if (!app(ConversionAvailability::class)->isAvailable()) {
            admin_toastr(exmtrans('storage_conversion.message_unavailable'), 'error');
            return $back;
        }
        try {
            $message = $action($table);
            admin_toastr(exmtrans('storage_conversion.' . $message));
        } catch (StorageConversionException $ex) {
            // service messages carry codes and table names, never cell values
            admin_toastr(exmtrans('storage_conversion.message_refused') . ' ' . $ex->getMessage(), 'error');
        }
        return $back;
    }

    /** The administrator's explicit attestation from the submitted form (all boxes required). */
    protected function attestation(Request $request): DrainAttestation
    {
        $confirmations = [];
        foreach (DrainAttestation::REQUIRED as $code) {
            $confirmations[$code] = $request->boolean('attest_' . $code);
        }
        $user = \Exment::user();
        return new DrainAttestation(
            (string)($user ? $user->getUserId() : ''),
            new \DateTimeImmutable(),
            $confirmations,
            trim((string)$request->get('attest_measures'))
        );
    }

    protected function dispatchFor(CustomTable $table, string $operation): void
    {
        StorageConversionJob::dispatch((int)$table->id, $operation, TableStorage::fresh($table)->generation());
    }

    /**
     * @param mixed $id
     */
    protected function table($id): CustomTable
    {
        $table = CustomTable::getEloquent($id);
        if (!($table instanceof CustomTable)) {
            abort(404);
        }
        return $table;
    }
}
