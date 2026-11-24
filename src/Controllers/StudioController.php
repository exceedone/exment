<?php

namespace Exceedone\Exment\Controllers;

use ExmentAdminCore\Admin\Auth\Permission as Checker;
use ExmentAdminCore\Admin\Layout\Content;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\MenuType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Services\Studio\BlankFormBuilder;
use Exceedone\Exment\Services\Studio\ExistingTableFormBuilder;
use Exceedone\Exment\Services\Studio\RelationDiagram;
use Exceedone\Exment\Services\Studio\ScreenAction;
use Exceedone\Exment\Services\Studio\ScreenBundle;
use Exceedone\Exment\Services\Studio\ScreenChildren;
use Exceedone\Exment\Services\Studio\ScreenEditor;
use Exceedone\Exment\Services\Studio\ScreenLayout;
use Exceedone\Exment\Services\Studio\ScreenList;
use Exceedone\Exment\Services\Studio\SheetImporter;
use Exceedone\Exment\Services\Studio\SheetReader;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Studio: the screens of the site in one place (ISS00231).
 *
 * Phase 1 lists the forms and views of each table, the dashboards and the
 * pages of the page plugins, with the relation diagram of the tables.
 * Phase 2 makes an input screen: from a blank page, where the items placed
 * on it become a new table and its columns, or from the columns of a table
 * that exists, which makes a new form of it and no table.
 */
class StudioController extends AdminControllerBase
{
    public const MODE_NEW = 'new';
    public const MODE_EXISTING = 'existing';
    public const MODE_EDIT = 'edit';

    /** The session key of the Excel or CSV file read for the builder. */
    public const SHEET_SESSION_KEY = 'studio_sheet';

    public function __construct()
    {
        $this->setPageInfo(exmtrans('studio.header'), exmtrans('studio.header'), exmtrans('studio.description'), 'fa-th-large');
    }

    /**
     * @return Content|\Symfony\Component\HttpFoundation\Response
     */
    public function index(Request $request, Content $content)
    {
        $list = new ScreenList();
        if (!$list->available()) {
            Checker::error();
            return response('');
        }

        $tables = $list->tables()->map(function ($table) {
            return ['id' => intval($table->id), 'name' => strval($table->table_view_name)];
        })->all();

        $this->AdminContent($content);
        $content->row(view('exment::studio.index', [
            'tables' => $list->tableCards(),
            'dashboards' => $list->dashboards(),
            'plugin_pages' => $list->pluginPages(),
            'diagram' => RelationDiagram::layout($tables, $list->relations()),
            'diagram_box' => ['width' => RelationDiagram::BOX_WIDTH, 'height' => RelationDiagram::BOX_HEIGHT],
            'create_url' => $this->canBuild() ? admin_urls('studio', 'create') : null,
            'app_url' => admin_url('app'),
        ]));
        return $content;
    }

    /**
     * Whether the login user may make an input screen at all: a new table
     * (system permission of the tables), or a form of a table they may set.
     */
    protected function canBuild(): bool
    {
        return BlankFormBuilder::available() || ExistingTableFormBuilder::available();
    }

    /**
     * The builder of an input screen: for a new table, or for a table that exists.
     *
     * @return Content|\Symfony\Component\HttpFoundation\Response
     */
    public function create(Request $request, Content $content)
    {
        if (!$this->canBuild()) {
            Checker::error();
            return response('');
        }

        $canNew = BlankFormBuilder::available();
        $old = $request->old();
        $hasOld = is_array($old) && array_key_exists('fields', $old);
        $mode = $hasOld ? strval(array_get($old, 'mode')) : '';
        if (!in_array($mode, [static::MODE_NEW, static::MODE_EXISTING], true) || ($mode === static::MODE_NEW && !$canNew)) {
            $mode = $canNew ? static::MODE_NEW : static::MODE_EXISTING;
        }
        $screen = null;
        if ($hasOld) {
            $screen = $mode === static::MODE_NEW ? BlankFormBuilder::normalize($old) : ExistingTableFormBuilder::normalize($old);
        }

        $this->setPageInfo(exmtrans('studio.header'), exmtrans('studio.create_header'), exmtrans('studio.create_description'), 'fa-th-large');
        $this->AdminContent($content);
        $content->row(view('exment::studio.create', $this->builderData($request, [
            'mode' => $mode,
            'can_new' => $canNew,
            'action' => admin_urls('studio'),
            'screen' => $screen,
        ])));
        return $content;
    }

    /**
     * What the builder page is drawn with; $data gives the way (mode), the screen and where it is saved.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function builderData(Request $request, array $data): array
    {
        $old = $request->old();
        $hasOld = is_array($old) && array_key_exists('fields', $old);
        return $data + [
            'edit' => null,
            'existing_tables' => ExistingTableFormBuilder::tables(),
            'palette' => BlankFormBuilder::palette(),
            'menu_parents' => Menu::where('menu_type', MenuType::PARENT_NODE)->orderBy('order')->get(['id', 'title'])->toArray(),
            'back_url' => admin_url('studio'),
            'errors_by_key' => session('studio_errors', []),
            'max_fields' => BlankFormBuilder::MAX_FIELDS,
            'sheet_url' => admin_urls('studio', 'sheet'),
            'sheet_max_rows' => SheetReader::MAX_ROWS,
            'layout_presets' => ScreenLayout::PRESETS,
            'max_children' => ScreenChildren::MAX_CHILDREN,
            'reference_tables' => BlankFormBuilder::referenceTables(),
            'old_children' => $hasOld && is_string(array_get($old, 'children')) ? (json_decode($old['children'], true) ?: []) : [],
            'old_actions' => $hasOld ? ScreenAction::normalize(array_get($old, 'actions')) : [],
            'old_bundle' => $hasOld ? ScreenBundle::normalize(array_get($old, 'bundle')) : null,
            'bundle_sources' => ScreenBundle::SOURCES,
            'can_dashboard' => ScreenBundle::dashboardType() !== null,
            'max_actions' => ScreenAction::MAX_ACTIONS,
            'action_types' => ScreenAction::TARGET_TYPES,
        ];
    }

    /**
     * The form of a screen, when the login user may change it in the builder.
     */
    protected function editableForm(string $id): ?CustomForm
    {
        $form = CustomForm::find($id);
        return $form instanceof CustomForm && ScreenEditor::available($form) ? $form : null;
    }

    /**
     * Change a screen in the builder it was made in (ScreenEditor).
     *
     * @param string|int $id the id of the form
     * @return Content|\Symfony\Component\HttpFoundation\Response
     */
    public function edit(Request $request, Content $content, $id)
    {
        $form = $this->editableForm(strval($id));
        if ($form === null) {
            Checker::error();
            return response('');
        }
        $table = $form->custom_table;
        $old = $request->old();
        $screen = is_array($old) && array_key_exists('fields', $old) ? ScreenEditor::normalize($form, $old) : ScreenEditor::load($form);
        $screen['table_id'] = intval($table->id);

        $this->setPageInfo(exmtrans('studio.header'), exmtrans('studio.edit_header'), sprintf(exmtrans('studio.edit_description'), $table->table_view_name), 'fa-th-large');
        $this->AdminContent($content);
        $content->row(view('exment::studio.create', $this->builderData($request, [
            'mode' => static::MODE_EDIT,
            'can_new' => false,
            'action' => admin_urls('studio', 'screens', $form->id),
            'screen' => $screen,
            'edit' => [
                'table_view_name' => strval($table->table_view_name),
                'can_add_columns' => ScreenEditor::canAddColumns($form),
                'new_types' => ScreenEditor::newTypes(),
                'adjusted' => $screen['adjusted'],
                'form_url' => admin_urls('form', $table->table_name, $form->id, 'edit'),
                'data_url' => admin_urls('data', $table->table_name, 'create?formid=' . $form->suuid),
                'has_details' => $form->custom_form_blocks()->where('form_block_type', '<>', FormBlockType::DEFAULT)->where('available', true)->exists(),
            ],
        ])));
        return $content;
    }

    /**
     * Save a screen changed in the builder.
     *
     * @param string|int $id the id of the form
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function update(Request $request, $id)
    {
        $form = $this->editableForm(strval($id));
        if ($form === null) {
            Checker::error();
            return response('');
        }
        $screen = ScreenEditor::normalize($form, $request->only(['table_view_name', 'fields', 'layout']));
        $errors = ScreenEditor::validate($form, $screen);
        $actions = ScreenAction::normalize($request->input('actions'));
        if (count($actions) > 0) {
            if (!ScreenEditor::canAddColumns($form)) {
                $errors['actions'] = exmtrans('studio.error.action_permission');
            } else {
                $errors += ScreenAction::validate($actions, ScreenEditor::targetsOf($screen['fields']));
            }
        }
        if (count($errors) > 0) {
            admin_toastr(exmtrans('studio.error.check_input'), 'error');
            return back()->withInput()->with('studio_errors', $errors);
        }

        $columnIds = ScreenEditor::save($form, $screen);
        if (count($actions) > 0) {
            ScreenAction::build($form->custom_table, $columnIds, $actions);
        }
        admin_toastr(sprintf(exmtrans('studio.saved_form'), $screen['table_view_name']));
        return redirect()->to(admin_urls('studio', 'screens', $form->id, 'edit'));
    }

    /**
     * Make the built screen: a new table with its columns, form and view, or
     * a new form of a table that exists.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function store(Request $request)
    {
        $mode = strval($request->input('mode', static::MODE_NEW));
        if ($mode === static::MODE_EXISTING) {
            return $this->storeExisting($request);
        }

        if (!BlankFormBuilder::available()) {
            Checker::error();
            return response('');
        }

        $screen = BlankFormBuilder::normalize($request->only(['table_view_name', 'table_name', 'menu_parent_id', 'fields', 'layout']));
        // the details: child tables entered as tables under the items (ScreenChildren).
        // A formula of the parent may sum an item of a detail: read it again with them.
        $children = ScreenChildren::normalize($request->input('children'), $screen['table_name']);
        $screen['fields'] = BlankFormBuilder::resolveFormulas($screen['fields'], $children);

        $errors = BlankFormBuilder::validate($screen);
        $errors += ScreenChildren::validate($children);
        // the buttons of the screen (ScreenAction): they set an item placed on it
        $actions = ScreenAction::normalize($request->input('actions'));
        $errors += ScreenAction::validate($actions, array_map(function ($field) {
            return [
                'type' => $field['type'],
                'choices' => $field['type'] === ColumnType::SELECT ? array_values(array_filter(array_map('trim', explode("\n", $field['choices'])), function ($choice) {
                    return $choice !== '';
                })) : null,
            ];
        }, $screen['fields']));
        // the app made with it (ScreenBundle): a kanban, a calendar, a chart, a dashboard
        $bundle = ScreenBundle::normalize($request->input('bundle'));
        $errors += ScreenBundle::validate($bundle, $screen['fields']);

        // the read sheet whose rows are taken in, when asked
        $sheet = null;
        if ($request->boolean('import_data')) {
            $sheet = $this->storedSheet(strval($request->input('sheet_token')));
            if ($sheet === null) {
                $errors['sheet'] = exmtrans('studio.error.sheet_expired');
            }
        }
        if (count($errors) > 0) {
            admin_toastr(exmtrans('studio.error.check_input'), 'error');
            return back()->withInput()->with('studio_errors', $errors);
        }

        $table = BlankFormBuilder::build($screen);
        if (count($children) > 0) {
            try {
                ScreenChildren::build($table, $children);
            } catch (\Throwable $e) {
                // the details deleted what they made; the parent is not left half made either
                $table->delete();
                throw $e;
            }
        }
        // the columns were made one per item, in the posted order
        $columnIds = $table->custom_columns()->orderBy('id')->pluck('id')->map(function ($id) {
            return intval($id);
        })->all();
        if (count($actions) > 0) {
            try {
                ScreenAction::build($table, $columnIds, $actions);
            } catch (\Throwable $e) {
                // deleting the table deletes its buttons too
                $table->delete();
                throw $e;
            }
        }
        $made = [];
        if (ScreenBundle::any($bundle)) {
            try {
                $made = ScreenBundle::build($table, $columnIds, $bundle);
            } catch (\Throwable $e) {
                // deleting the table deletes its views too (the dashboard deletes itself)
                $table->delete();
                throw $e;
            }
        }
        $madeMessage = count($made) > 0
            ? sprintf(exmtrans('studio.bundle_made'), implode(exmtrans('studio.name_separator'), array_map(function ($name) {
                return sprintf(exmtrans('studio.name_quote'), $name);
            }, $made)))
            : '';

        if ($sheet !== null) {
            try {
                $rows = SheetReader::read($sheet['path'], $sheet['ext'])['rows'];
                $result = SheetImporter::import($table, $rows, $screen['fields']);
            } catch (\Throwable $e) {
                // the table is not left half made
                $table->delete();
                throw $e;
            }
            $this->forgetSheet();

            $message = sprintf(exmtrans('studio.created_with_data'), $table->table_view_name, $result['saved']) . $madeMessage;
            if ($result['cleared'] > 0) {
                $message .= sprintf(exmtrans('studio.created_cleared'), $result['cleared']);
            }
            admin_toastr($message, $result['cleared'] > 0 ? 'warning' : 'success');
            return redirect()->to(admin_urls('data', $table->table_name));
        }

        admin_toastr(sprintf(exmtrans('studio.created'), $table->table_view_name) . $madeMessage);
        return redirect()->to(admin_urls('data', $table->table_name, 'create'));
    }

    /**
     * Read an Excel or CSV file for the builder: the items guessed from its
     * columns. The file is kept (admin_tmp) until the screen is saved, to take
     * its rows in then.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function readSheet(Request $request)
    {
        if (!BlankFormBuilder::available()) {
            return response()->json(['result' => false, 'message' => trans('admin.deny')], 403);
        }

        $file = $request->file('sheet');
        $extension = $file instanceof UploadedFile ? strtolower($file->getClientOriginalExtension()) : '';
        if (!($file instanceof UploadedFile) || !$file->isValid() || !in_array($extension, SheetReader::EXTENSIONS, true)) {
            return response()->json(['result' => false, 'message' => exmtrans('studio.error.sheet_file')], 422);
        }
        if ($file->getSize() > SheetReader::MAX_FILE_MB * 1024 * 1024) {
            return response()->json(['result' => false, 'message' => sprintf(exmtrans('studio.error.sheet_size'), SheetReader::MAX_FILE_MB)], 422);
        }

        $this->forgetSheet();
        $stored = $file->storeAs(\Exceedone\Exment\Facades\ExmentFacade::getTmpFolderPath('studio', false), short_uuid() . '.' . $extension, Define::DISKNAME_ADMIN_TMP);
        if ($stored === false) {
            return response()->json(['result' => false, 'message' => exmtrans('studio.error.sheet_file')], 422);
        }
        $path = getFullpath($stored, Define::DISKNAME_ADMIN_TMP);

        try {
            $sheet = SheetReader::read($path, $extension);
        } catch (\Throwable $e) {
            Storage::disk(Define::DISKNAME_ADMIN_TMP)->delete($stored);
            return response()->json(['result' => false, 'message' => exmtrans('studio.error.sheet_read')], 422);
        }
        if (count($sheet['headers']) === 0) {
            Storage::disk(Define::DISKNAME_ADMIN_TMP)->delete($stored);
            return response()->json(['result' => false, 'message' => exmtrans('studio.error.sheet_empty')], 422);
        }

        $token = short_uuid();
        session([static::SHEET_SESSION_KEY => ['token' => $token, 'file' => $stored, 'ext' => $extension]]);

        return response()->json([
            'result' => true,
            'token' => $token,
            'file_name' => $file->getClientOriginalName(),
            'fields' => SheetReader::fields($sheet),
            'row_count' => count($sheet['rows']),
            'column_count' => count($sheet['headers']),
            'truncated' => $sheet['truncated'],
        ]);
    }

    /**
     * The read sheet of this session, when the token is its own.
     *
     * @return array{path: string, ext: string}|null
     */
    protected function storedSheet(string $token): ?array
    {
        $kept = session(static::SHEET_SESSION_KEY);
        if (!is_array($kept) || $token === '' || !hash_equals(strval($kept['token'] ?? ''), $token)) {
            return null;
        }
        if (!Storage::disk(Define::DISKNAME_ADMIN_TMP)->exists($kept['file'])) {
            return null;
        }
        return ['path' => getFullpath($kept['file'], Define::DISKNAME_ADMIN_TMP), 'ext' => strval($kept['ext'])];
    }

    /**
     * Delete the read sheet of this session.
     */
    protected function forgetSheet(): void
    {
        $kept = session()->pull(static::SHEET_SESSION_KEY);
        if (is_array($kept) && isset($kept['file'])) {
            Storage::disk(Define::DISKNAME_ADMIN_TMP)->delete($kept['file']);
        }
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function storeExisting(Request $request)
    {
        if (!ExistingTableFormBuilder::available()) {
            Checker::error();
            return response('');
        }

        $screen = ExistingTableFormBuilder::normalize($request->only(['table_view_name', 'table_id', 'fields', 'layout']));
        $errors = ExistingTableFormBuilder::validate($screen);
        // a button is a data operation of the table: made by those who may set up the table
        $actions = ScreenAction::normalize($request->input('actions'));
        if (count($actions) > 0) {
            $target = CustomTable::getEloquent($screen['table_id']);
            if (!isset($target) || !$target->hasPermission(Permission::CUSTOM_TABLE)) {
                $errors['actions'] = exmtrans('studio.error.action_permission');
            } else {
                $errors += ScreenAction::validate($actions, array_map(function ($field) {
                    $column = CustomColumn::getEloquent($field['column_id']);
                    return isset($column)
                        ? ['type' => strval($column->column_type), 'choices' => ScreenAction::choicesOf($column)]
                        : ['type' => '', 'choices' => null];
                }, $screen['fields']));
            }
        }
        if (count($errors) > 0) {
            admin_toastr(exmtrans('studio.error.check_input'), 'error');
            return back()->withInput()->with('studio_errors', $errors);
        }

        // the form and its buttons, or nothing
        $form = \Illuminate\Support\Facades\DB::transaction(function () use ($screen, $actions) {
            $form = ExistingTableFormBuilder::build($screen);
            if (count($actions) > 0) {
                ScreenAction::build(CustomTable::getEloquent($form->custom_table_id), array_column($screen['fields'], 'column_id'), $actions);
            }
            return $form;
        });
        $table = CustomTable::getEloquent($form->custom_table_id);

        admin_toastr(sprintf(exmtrans('studio.created_form'), $form->form_view_name, $table->table_view_name));
        return redirect()->to(admin_urls('data', $table->table_name, 'create?formid=' . $form->suuid));
    }
}
