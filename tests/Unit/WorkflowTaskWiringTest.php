<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Form\Navbar\WorkflowTaskNav;
use Tests\TestCase;

/**
 * Guard test for the wiring of the "un-actioned workflow task" feature.
 *
 * The feature is spread over 8 loosely-coupled places (route, permission whitelist,
 * navbar registration, css/js asset list, blade view, lang keys, migration, JS endpoint
 * name). None of them fails loudly when it drifts: a renamed route just gives a silent
 * 404 inside an AJAX poll, a missing css entry just makes the badge invisible, a missing
 * permission case just 403s. This test pins every link of the chain.
 *
 * It only reads files from the package, so it runs without any test data.
 */
class WorkflowTaskWiringTest extends TestCase
{
    /**
     * @return string package root (exment package directory)
     */
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @param string $relative
     * @return string file content
     */
    private function read(string $relative): string
    {
        $path = $this->root() . '/' . $relative;
        $this->assertFileExists($path, "Missing file: {$relative}");

        $content = file_get_contents($path);
        $this->assertNotFalse($content, "Cannot read: {$relative}");

        return $content;
    }

    /**
     * Every file the feature ships must be present.
     *
     * @return void
     */
    public function testAllFeatureFilesExist()
    {
        $files = [
            'src/Controllers/WorkflowTaskController.php',
            'src/Services/Workflow/WorkflowTaskService.php',
            'src/Services/Notify/SameOrganizationWorkflowNotify.php',
            'src/Model/WorkflowTaskRead.php',
            'src/Form/Navbar/WorkflowTaskNav.php',
            'resources/views/workflow_task/index.blade.php',
            'public/vendor/exment/css/workflow_task_navbar.css',
            'public/vendor/exment/js/workflow_task_navbar.js',
            'database/migrations/2026_07_01_000000_create_workflow_task_reads_table.php',
            'database/migrations/2026_09_05_000000_workflow_task_reads_rebuild.php',
        ];

        foreach ($files as $file) {
            $this->assertFileExists($this->root() . '/' . $file, "Missing file: {$file}");
        }
    }

    /**
     * The three admin routes and the webapi route must stay registered.
     *
     * @return void
     */
    public function testRoutesAreRegistered()
    {
        $content = $this->read('src/Providers/RouteServiceProvider.php');

        $this->assertStringContainsString('"workflow_task", \'WorkflowTaskController@index\'', $content);
        $this->assertStringContainsString('"workflow_task/read", \'WorkflowTaskController@read\'', $content);
        $this->assertStringContainsString('"workflow_task/readAll", \'WorkflowTaskController@readAll\'', $content);
        $this->assertStringContainsString('"workflow_task/unreadAll", \'WorkflowTaskController@unreadAll\'', $content);
        $this->assertStringContainsString('"workflow_task/rowCheck", \'WorkflowTaskController@rowCheck\'', $content);
        $this->assertStringContainsString('"workflowTaskPage", \'ApiController@workflowTaskPage\'', $content);
    }

    /**
     * The controller must expose exactly the actions the routes point at.
     *
     * @return void
     */
    public function testControllerExposesRoutedActions()
    {
        foreach (['index', 'read', 'readAll', 'unreadAll', 'rowCheck'] as $action) {
            $this->assertTrue(
                method_exists(\Exceedone\Exment\Controllers\WorkflowTaskController::class, $action),
                "WorkflowTaskController::{$action}() is routed but missing"
            );
        }

        $this->assertTrue(
            method_exists(\Exceedone\Exment\Controllers\ApiController::class, 'workflowTaskPage'),
            'ApiController::workflowTaskPage() is routed but missing'
        );
    }

    /**
     * The list is a personal screen: any logged-in user may open it, so the endpoint
     * must stay in the always-allowed branch of Permission::validateEndpoint.
     *
     * @return void
     */
    public function testEndpointIsInPermissionWhitelist()
    {
        $content = $this->read('src/Auth/Permission.php');

        $this->assertMatchesRegularExpression(
            '/case\s+"workflow_task"\s*:/',
            $content,
            'workflow_task must stay in the always-allowed endpoint list, otherwise the screen 403s'
        );
    }

    /**
     * The navbar icon and its css/js must stay in the GLOBAL asset list of Bootstrap.
     * Per-page registration does not survive pjax navigation in this admin theme.
     *
     * @return void
     */
    public function testNavbarAndAssetsAreRegisteredGlobally()
    {
        $content = $this->read('src/Middleware/Bootstrap.php');

        $this->assertStringContainsString('Form\\Navbar\\WorkflowTaskNav', $content);
        $this->assertStringContainsString('vendor/exment/css/workflow_task_navbar.css', $content);
        $this->assertStringContainsString('vendor/exment/js/workflow_task_navbar.js', $content);
    }

    /**
     * The navbar JS polls one endpoint name. If the route name changes and the JS does
     * not (or the other way round) the badge silently stops updating.
     *
     * @return void
     */
    public function testJsCallsTheRegisteredEndpointName()
    {
        $js = $this->read('public/vendor/exment/js/workflow_task_navbar.js');
        $routes = $this->read('src/Providers/RouteServiceProvider.php');

        $this->assertStringContainsString("'workflowTaskPage'", $js);
        $this->assertStringContainsString('"workflowTaskPage"', $routes);

        // the css/js hook on this class name; the navbar html must render it
        $nav = $this->read('src/Form/Navbar/WorkflowTaskNav.php');
        $css = $this->read('public/vendor/exment/css/workflow_task_navbar.css');

        $this->assertStringContainsString('navbar-workflow-task', $nav);
        $this->assertStringContainsString('navbar-workflow-task', $js);
        $this->assertStringContainsString('navbar-workflow-task', $css);
    }

    /**
     * The "all tasks" operations sit in the dropdown the notification list uses: a
     * SwalMenuButton, one entry per operation, each one a confirm dialog followed by an ajax
     * post. The menu is built in the controller, so its urls already carry the filter and the
     * button acts on the list that is on screen.
     *
     * Deleting every record is deliberately NOT one of the entries - see getMenuList().
     *
     * @return void
     */
    public function testBatchMenuMirrorsTheNotificationList()
    {
        $blade = $this->read('resources/views/workflow_task/index.blade.php');
        $controller = $this->read('src/Controllers/WorkflowTaskController.php');

        // the same renderer notify_navbar uses, fed from the controller
        $this->assertStringContainsString('SwalMenuButton($menulist)', $blade);
        $this->assertStringContainsString("'menulist' => \$this->getMenuList(\$service->filter())", $controller);

        // both directions are offered, and both go through a confirm dialog
        $this->assertStringContainsString("admin_url('workflow_task/readAll')", $controller);
        $this->assertStringContainsString("admin_url('workflow_task/unreadAll')", $controller);
        $this->assertSame(2, substr_count($controller, "'method' => 'post',"), 'both entries must post');
        $this->assertStringContainsString("'confirm' => trans('admin.confirm'),", $controller);
        $this->assertStringContainsString("'cancel' => trans('admin.cancel'),", $controller);

        // the filter travels with the button, so it marks what the user is looking at
        $this->assertStringContainsString('http_build_query($query)', $controller);

        // "delete every record" is not an option here: on notify_navbar a row is a
        // notification, here every row is a real record of a real table
        $this->assertStringNotContainsString('delete_all', $controller);
        $this->assertStringNotContainsString('delete_all', $blade);

        // the endpoints answer the ajax the menu sends, and still answer a plain form post -
        // the feature must not depend on the dropdown
        $this->assertStringContainsString('if ($request->ajax())', $controller);
        $this->assertStringContainsString("return redirect(admin_url('workflow_task'));", $controller);
    }

    /**
     * The batch "mark as seen" takes its keys straight from the browser, so it must not trust
     * them. markSeenSelected() keeps only the keys that are in pendingKeys() - the permission
     * scoped list the screen itself is built from - which is what stops any logged-in user
     * from filling workflow_task_reads with rows for records they cannot even see.
     *
     * @return void
     */
    public function testRowCheckDoesNotTrustTheSubmittedKeys()
    {
        $controller = $this->read('src/Controllers/WorkflowTaskController.php');
        $service = $this->read('src/Services/Workflow/WorkflowTaskService.php');

        // the endpoint must never reach markSeen() directly
        $this->assertStringContainsString('markSeenSelected($keys)', $controller);
        $this->assertStringNotContainsString('->markSeen($keys)', $controller);

        $start = strpos($service, 'public function markSeenSelected');
        $this->assertNotFalse($start, 'markSeenSelected() is gone');
        $body = substr($service, $start, strpos($service, 'public function markAllUnseen') - $start);

        // the guard: the submitted ids are checked against the permission scoped pendingQuery
        // of their own table, restricted to exactly those ids - never against a list of
        // everything pending (that list grows with the data, the selection does not)
        $this->assertStringContainsString('$this->pendingQuery($custom_table, true)', $body);
        $this->assertStringContainsString("->whereIn(\$tableName . '.id', \$chunk)", $body);
        $this->assertStringNotContainsString('pendingKeys()', $body, 'the cost must follow the selection, not the dataset');

        // "nothing to update" is an answer, not a silent success
        $this->assertStringContainsString("'result' => false,", $controller);
        $this->assertStringContainsString("exmtrans('workflow_task.message.check_notfound')", $controller);

        // "?keys[]=1:1" is an array, and (string)[] is a PHP warning that Laravel raises as an
        // exception: a malformed request has to be answered, not turned into a 500
        $this->assertStringContainsString('is_string($raw)', $controller);
        $this->assertStringNotContainsString("(string)\$request->get('keys')", $controller);

        // the body is bounded by post_max_size only, so the array built from it is capped here
        $this->assertStringContainsString('const MAX_CHECK_KEYS', $controller);
        $this->assertStringContainsString('self::MAX_CHECK_KEYS + 1', $controller);
        $this->assertStringContainsString('->take(self::MAX_CHECK_KEYS)', $controller);

        // an empty selection must not run any query just to answer "nothing"
        $this->assertStringContainsString('if (empty($taskKeys)) {', $body);
        $this->assertLessThan(
            strpos($body, '$this->workflowCustomTables()'),
            strpos($body, 'if (empty($taskKeys)) {'),
            'the empty check has to come before the queries it saves'
        );
    }

    /**
     * The navbar poll must not recompute the whole scan for every tab and every tick: the
     * payload is cached per user for one poll interval, and every write to the user's own
     * view bumps the cache version so THEIR next poll is fresh. Without the bump, "mark all
     * as seen" would leave the old badge count on screen for up to a whole interval.
     *
     * @return void
     */
    public function testNavbarPollIsCachedPerUserAndInvalidatedByOwnWrites()
    {
        $api = $this->read('src/Controllers/ApiController.php');
        $service = $this->read('src/Services/Workflow/WorkflowTaskService.php');
        $action = $this->read('src/Model/WorkflowAction.php');
        $value = $this->read('src/Model/CustomValue.php');

        // the endpoint answers from the cache, keyed by the service, for one poll interval
        $this->assertStringContainsString('WorkflowTaskService::navbarCacheKey()', $api);
        $this->assertStringContainsString(
            '\Cache::remember($cacheKey, \Exceedone\Exment\Form\Navbar\WorkflowTaskNav::interval()',
            $api
        );

        // every own write bumps the version: marking, unmarking, acting, deleting
        foreach (['public function markSeen(', 'public function markAllUnseen('] as $method) {
            $start = strpos($service, $method);
            $this->assertNotFalse($start, $method . ' is gone');
            $this->assertStringContainsString(
                'static::navbarCacheForget();',
                substr($service, $start, 1200),
                $method . ' must make the next poll recompute'
            );
        }
        $this->assertStringContainsString('WorkflowTaskService::navbarCacheForget();', $action);
        $this->assertStringContainsString('WorkflowTaskService::navbarCacheForget();', $value);
    }

    /**
     * "Mark all as unseen" is a display-state reset: it may remove read marks and nothing else.
     * If it ever reached a custom value model, one menu click would destroy real records.
     *
     * @return void
     */
    public function testUnreadAllOnlyRemovesTheReadMarks()
    {
        $service = $this->read('src/Services/Workflow/WorkflowTaskService.php');

        $start = strpos($service, 'public function markAllUnseen');
        $this->assertNotFalse($start, 'markAllUnseen() is gone');
        $body = substr($service, $start, strpos($service, 'private function pendingKeys') - $start);

        // only workflow_task_reads, only this user
        $this->assertStringContainsString("WorkflowTaskRead::where('target_user_id', \$userId)", $body);
        $this->assertStringNotContainsString('getModelName(', $body, 'it must not open a custom value model');
        $this->assertStringNotContainsString('forceDelete', $body);

        // scoped like the "mark all as seen" it undoes, and chunked for the same reason
        $this->assertStringContainsString('$this->pendingQuery($custom_table, false)', $body);
        $this->assertStringContainsString('array_chunk($ids, 1000)', $body);
    }

    /**
     * The whole row opens the task, not only the label. That is not done with a script of its
     * own: the row link carries the "rowclick" class, and Exment's CommonEvent.tableHoverLink()
     * - which already runs on every admin page - turns the surrounding <tr> into the click
     * target. Both halves of that contract have to stay put; if either one drifts the row
     * silently stops reacting and nothing fails.
     *
     * @return void
     */
    public function testWholeRowOpensTheTask()
    {
        $blade = $this->read('resources/views/workflow_task/index.blade.php');

        // the row link, and only the row link, is the rowclick target
        $this->assertStringContainsString(
            '<a class="rowclick" href=',
            $blade,
            'the row link lost its rowclick class, so clicking the row does nothing'
        );
        $this->assertStringContainsString(
            "admin_url('workflow_task/read')",
            $blade,
            'the row link must still point at the read route'
        );
        $this->assertSame(
            1,
            substr_count($blade, 'class="rowclick"'),
            'two rowclick targets in one row make tableHoverLink() pick an arbitrary one'
        );

        // ... and the mechanism it relies on
        $js = $this->read('public/vendor/exment/js/common.js');
        $this->assertStringContainsString(
            "$('table').find('[data-id],.rowclick').closest('tr')",
            $js,
            'tableHoverLink() no longer binds rowclick rows'
        );
        $this->assertStringContainsString(
            "linkElem.closest('a,.rowclick').trigger('click')",
            $js,
            'tableHoverLink() no longer forwards the row click to the link'
        );
        // a click on the link itself must stay a plain link (ctrl+click, copy address)
        $this->assertStringContainsString(
            "if ($(ev.target).closest('a,.rowclick').length > 0) {",
            $js,
            'without this guard a click on the link is handled twice'
        );
    }

    /**
     * The filter must sit where every read passes through, not on the rows: the two COUNTs, the
     * id reads and the page all go through pendingQuery(), so a filter applied anywhere else
     * would give a paginator that disagrees with the rows it paginates.
     *
     * @return void
     */
    public function testFilterIsAppliedWhereEveryReadPassesThrough()
    {
        $content = $this->read('src/Services/Workflow/WorkflowTaskService.php');

        $start = strpos($content, 'private function pendingQuery(');
        $this->assertNotFalse($start, 'pendingQuery() is gone');
        $end = strpos($content, "\n    }", $start);
        $method = substr($content, $start, $end === false ? null : $end - $start);

        $this->assertStringContainsString('$this->applyFilter($query', $method, 'the filter must be applied here');
        $this->assertStringContainsString("\$this->filter['seen']", $method, 'the read/unread filter belongs in SQL');

        // the sort direction has to reach the per-table ORDER BY as well: page 3 of an ascending
        // list is built from the OLDEST rows of every table
        $page = strpos($content, 'public function getPage(');
        if ($page === false) {
            // not a position 0: the method this whole block is about is gone
            $this->fail('getPage() must exist in the service');
        }
        $pageEnd = strpos($content, "\n    }", $page);
        $pageMethod = substr($content, $page, $pageEnd === false ? null : $pageEnd - $page);
        $this->assertStringContainsString('$direction = $this->filter[\'sort\']', $pageMethod);
        $this->assertStringContainsString("orderBy(\$tableName . '.updated_at', \$direction)", $pageMethod);
        $this->assertStringNotContainsString(
            "orderBy(\$tableName . '.updated_at', 'desc')",
            $pageMethod,
            'a hard coded direction ignores what the user picked'
        );

        // and nothing from the query string may reach the builder unchecked
        $this->assertStringContainsString('public static function normalizeFilter(array $input): array', $content);
        $this->assertStringContainsString('checkdate(', $content, 'a date filter must be a real date');

        $controller = $this->read('src/Controllers/WorkflowTaskController.php');
        $this->assertStringContainsString(
            'WorkflowTaskService::normalizeFilter($request->all())',
            $controller,
            'the controller must normalize the request before it becomes a query'
        );
        // "mark all as seen" sits under a filtered list, so it has to mark that list
        $readAll = strpos($controller, 'public function readAll(');
        $this->assertNotFalse($readAll);
        $this->assertStringContainsString(
            'normalizeFilter($request->all())',
            substr($controller, $readAll),
            'mark-all must follow the filter the button is drawn under'
        );
    }

    /**
     * Record ids are only unique inside one table. Two workflow tables holding the same id with
     * the same timestamp have nothing left to decide the order, and a page boundary in the
     * middle of such a group loses or repeats rows.
     *
     * @return void
     */
    public function testTheDisplayOrderIsTotal()
    {
        $service = \Exceedone\Exment\Services\Workflow\WorkflowTaskService::class;
        $entry = new \ReflectionMethod($service, 'indexEntry');
        $entry->setAccessible(true);
        $sort = new \ReflectionMethod($service, 'sortIndex');
        $sort->setAccessible(true);
        $item = new \ReflectionMethod($service, 'indexItem');
        $item->setAccessible(true);

        // same timestamp and same id in two tables, same timestamp with two ids, and one
        // older row: every tie the comparator can meet is on the board
        $index = [
            $entry->invoke(null, 7, 1, '2026-01-02 03:04:05'),
            $entry->invoke(null, 3, 1, '2026-01-02 03:04:05'),
            $entry->invoke(null, 3, 2, '2026-01-02 03:04:05'),
            $entry->invoke(null, 3, 2, '2026-01-01 00:00:00'),
        ];

        $unpack = function (array $entries) use ($item) {
            return array_map(function ($packed) use ($item) {
                $i = $item->invoke(null, $packed);
                return $i['custom_table_id'] . ':' . $i['id'];
            }, $entries);
        };

        $desc = $index;
        $sort->invokeArgs(null, [&$desc, 'desc']);
        $asc = $index;
        $sort->invokeArgs(null, [&$asc, 'asc']);

        // newest first; same timestamp -> bigger id first; same id too -> the TABLE decides.
        // Without that last column two tables holding the same id with the same timestamp
        // have nothing left to order them, and a page boundary there loses or repeats a row.
        $this->assertSame(['3:2', '7:1', '3:1', '3:2'], $unpack($desc));
        $this->assertSame($unpack($asc), array_reverse($unpack($desc)), 'ascending is the exact mirror');
    }

    /**
     * The delete action must not be a second implementation of "delete a custom value". It
     * points at the record's own url with method DELETE, which is CustomValueController@destroy
     * - the same endpoint the grid delete button calls, with its permission checks, its
     * relation validation and its plugin hooks.
     *
     * @return void
     */
    public function testDeleteReusesTheCustomValueEndpoint()
    {
        $blade = $this->read('resources/views/workflow_task/index.blade.php');

        $this->assertStringContainsString('data-add-swal="{{ $row[\'url\'] }}"', $blade, 'the button must target the record itself');
        $this->assertStringContainsString('data-add-swal-method="delete"', $blade);
        $this->assertStringContainsString('data-add-swal-confirm=', $blade, 'a delete must be confirmed');
        $this->assertStringContainsString('$row[\'can_delete\']', $blade, 'the button is only drawn where the delete is allowed');

        // no delete route and no delete action of its own
        $routes = $this->read('src/Providers/RouteServiceProvider.php');
        $this->assertStringNotContainsString('workflow_task/delete', $routes, 'the feature must not add its own delete route');

        $controller = $this->read('src/Controllers/WorkflowTaskController.php');
        $this->assertStringNotContainsString('function destroy', $controller, 'deleting is CustomValueController\'s job');
        $this->assertStringNotContainsString('->delete()', $controller, 'this screen must not delete anything itself');

        // and the flag is the answer Exment itself gives
        $service = $this->read('src/Services/Workflow/WorkflowTaskService.php');
        $this->assertStringContainsString(
            "\$row['can_delete'] = \$value->enableDelete(true) === true;",
            $service,
            'the button must agree with the check the delete endpoint runs'
        );
    }

    /**
     * Selecting several rows and deleting them must stay the same operation as deleting them
     * one by one. The ids are per table, so the checked rows are grouped by their table and
     * each group is sent to that table's own CustomValueController@destroy - which already
     * accepts a comma separated id list. No new route, no second delete implementation.
     *
     * @return void
     */
    public function testBatchDeleteGroupsByTableAndReusesTheEndpoint()
    {
        $blade = $this->read('resources/views/workflow_task/index.blade.php');

        // the selection is the grid one, down to the class names: the select-all box, the
        // button group that appears once something is ticked, and the shared selection store.
        // Same markup as admin::grid.batch-actions, so it looks and behaves like notify_navbar.
        $this->assertStringContainsString('class="grid-select-all"', $blade);
        $this->assertStringContainsString('class="btn-group grid-select-all-btn"', $blade);
        $this->assertStringContainsString('class="hidden-xs selected"', $blade);
        $this->assertStringContainsString('class="grid-row-checkbox"', $blade);
        $this->assertStringContainsString('class="grid-batch-0"', $blade);
        $this->assertStringContainsString('class="grid-batch-1"', $blade);
        $this->assertStringContainsString('$.admin.grid.selects = {};', $blade);
        $this->assertStringContainsString('$.admin.grid.selected()', $blade);

        // the checkbox is drawn on EVERY row, because the dropdown can also mark a row as
        // seen - a row the user may not delete is still a row they may tick
        $start = strpos($blade, '<td class="column-__row_selector__ workflow-task-check">');
        $this->assertNotFalse($start, 'the row selector cell is gone');
        $cell = substr($blade, $start, strpos($blade, '</td>', $start) - $start);
        $this->assertStringNotContainsString('can_delete', $cell, 'the checkbox must not be behind a delete guard');
        $this->assertStringContainsString("data-id=\"{{ \$row['task_key'] }}\"", $cell);

        // grouping key: the task key carries the table, and the view maps it to that table's
        // delete endpoint. Two tables can both have a record 1, so the id alone is not enough.
        $this->assertStringContainsString("\$tableUrls = \$rows->pluck('table_url', 'custom_table_id');", $blade);
        $this->assertStringContainsString('var tableUrls = {!! json_encode($tableUrls,', $blade);
        $this->assertStringContainsString("var parts = String(key).split(':');", $blade);
        $this->assertStringContainsString('groups[url].push(parts[1]);', $blade);
        $this->assertStringContainsString("url + '/' + ids.join(',')", $blade);

        // the confirm dialog is Exment's, and postEvent is its documented hook for sending
        // something other than one plain request
        $this->assertStringContainsString('Exment.CommonEvent.ShowSwal(', $blade);
        $this->assertStringContainsString('postEvent: function (data)', $blade);
        $this->assertStringContainsString("method: 'delete'", $blade);
        $this->assertStringContainsString('CallbackExmentAjax', $blade);

        // one table refusing (a relation check, a lock) must not silently cancel the rest
        $this->assertStringContainsString('failed.push(', $blade);

        // HasResourceTableActions::destroy() walks the whole comma separated list and only
        // reports the verdict at the end, so a request that answers "failed" may still have
        // deleted some of its rows. Nothing in the answer says how many, so the list has to be
        // reloaded whatever came back - a screen still offering deleted rows is the worse bug.
        $failPos = strpos($blade, 'if (failed.length === 0)');
        $this->assertNotFalse($failPos, 'the failure branch is gone');
        $this->assertStringContainsString(
            "$.pjax.reload('#pjax-container');",
            substr($blade, $failPos),
            'a batch that reports failure must still refresh the list'
        );
        $this->assertStringNotContainsString(
            'if (deleted > 0)',
            $blade,
            'the reload must not depend on a count the answer never gives'
        );

        // and still no delete of its own anywhere
        $routes = $this->read('src/Providers/RouteServiceProvider.php');
        $this->assertStringNotContainsString('workflow_task/delete', $routes);
        $this->assertStringNotContainsString('workflow_task/batch', $routes);

        $controller = $this->read('src/Controllers/WorkflowTaskController.php');
        $this->assertStringNotContainsString('function destroy', $controller);
        $this->assertStringNotContainsString('->delete()', $controller);
        $this->assertStringNotContainsString('forceDelete', $controller);

        // the whole grouping rests on one property of the endpoint: it takes a comma
        // separated id list. If that ever became a single id, every batch would delete only
        // the first row of each table and say it succeeded.
        $endpoint = $this->read('src/Controllers/CustomValueController.php');
        $this->assertStringContainsString(
            'foreach (stringtoArray($id) as $i)',
            $endpoint,
            'destroy() must still accept a comma separated id list'
        );
        $this->assertStringContainsString(
            '$router->delete("{$endpointName}/{tableKey}/{id}", "$controllerName@destroy")',
            $routes,
            'the delete route must stay unconstrained, a comma list has to match {id}'
        );

        // the base url must come from the record itself, not be assembled in the view
        $service = $this->read('src/Services/Workflow/WorkflowTaskService.php');
        $this->assertStringContainsString(
            "\$row['table_url'] = \$value->getUrl(['list' => true]);",
            $service,
            'the batch base must be the same builder the single url uses'
        );

        // the view splits the task key on ":" to find the table, so the separator is a
        // contract between the two files, not an implementation detail of the service
        $this->assertStringContainsString(
            "return \$customTableId . ':' . \$morphId;",
            $service,
            'the task key separator changed - the batch delete would group by the wrong table'
        );
    }

    /**
     * The whole row is a link: CommonEvent.tableHoverLink binds the click on the <tr> and only
     * lets a click that lands inside an <a> through. A checkbox is not an <a>, so without a
     * stopPropagation on its cell, ticking a box would navigate away from the list.
     *
     * @return void
     */
    public function testTickingACheckboxDoesNotOpenTheTask()
    {
        $blade = $this->read('resources/views/workflow_task/index.blade.php');

        // the cell carries the grid's column class AND the hook this guard binds on
        $this->assertStringContainsString(
            'class="column-__row_selector__ workflow-task-check"',
            $blade,
            'the checkbox cell needs a hook'
        );
        $this->assertStringContainsString(
            "\$box.find('.workflow-task-check').on('click', function (ev) {",
            $blade
        );
        $this->assertStringContainsString('ev.stopPropagation();', $blade);

        // and the row click itself must still be there - the guard must not have been "fixed"
        // by removing the feature it protects
        $this->assertSame(1, substr_count($blade, 'class="rowclick"'), 'the row link is gone');

        // the handler in common.js is what makes this necessary; if its escape list ever grows
        // to include inputs this test should be revisited, so pin what it actually does
        $js = $this->read('public/vendor/exment/js/common.js');
        $this->assertStringContainsString("if (\$(ev.target).closest('a,.rowclick').length > 0) {", $js);
    }

    /**
     * Every translation key the feature uses must exist in every shipped locale.
     * (TranslationKeyParityTest checks locales against each other; this one checks
     * that the concrete keys this feature calls are actually there.)
     *
     * @return void
     */
    public function testTranslationKeysExistInAllLocales()
    {
        $required = [
            'workflow_task.header',
            'workflow_task.description',
            'workflow_task.table',
            'workflow_task.data',
            'workflow_task.status',
            'workflow_task.updated_at',
            'workflow_task.count',
            'workflow_task.empty',
            'workflow_task.seen_flg',
            'workflow_task.seen_options.0',
            'workflow_task.seen_options.1',
            'workflow_task.unseen_count',
            'workflow_task.mark_all_seen',
            'workflow_task.mark_all_unseen',
            'workflow_task.check_selected',
            'workflow_task.batch_all',
            'workflow_task.confirm_text.mark_all_seen',
            'workflow_task.confirm_text.mark_all_unseen',
            'workflow_task.message.mark_all_seen_succeeded',
            'workflow_task.message.mark_all_unseen_succeeded',
            'workflow_task.message.check_succeeded',
            'workflow_task.message.check_notfound',
            'workflow.same_org_notify.subject',
            'workflow.same_org_notify.body',
        ];

        $localeDirs = glob($this->root() . '/resources/lang/*', GLOB_ONLYDIR) ?: [];
        $this->assertGreaterThanOrEqual(2, count($localeDirs), 'expected at least 2 locales');

        foreach ($localeDirs as $dir) {
            $file = $dir . '/exment.php';
            if (!file_exists($file)) {
                continue;
            }

            $lang = require $file;
            $locale = basename($dir);

            foreach ($required as $key) {
                $this->assertNotNull(
                    data_get($lang, $key),
                    "Missing translation key '{$key}' in locale '{$locale}'"
                );
            }
        }
    }

    /**
     * The %s placeholders must match between locales, otherwise sprintf() in exmtrans()
     * produces a broken string in one language only.
     *
     * @return void
     */
    public function testPlaceholderCountMatchesBetweenLocales()
    {
        $keys = [
            'workflow_task.count' => 1,
            'workflow_task.unseen_count' => 1,
            'workflow.same_org_notify.body' => 4,
        ];

        $localeDirs = glob($this->root() . '/resources/lang/*', GLOB_ONLYDIR) ?: [];

        foreach ($localeDirs as $dir) {
            $file = $dir . '/exment.php';
            if (!file_exists($file)) {
                continue;
            }

            $lang = require $file;
            $locale = basename($dir);

            foreach ($keys as $key => $expected) {
                $value = data_get($lang, $key);
                $this->assertIsString($value, "'{$key}' must be a string in '{$locale}'");
                $this->assertSame(
                    $expected,
                    substr_count($value, '%s'),
                    "'{$key}' in locale '{$locale}' must have exactly {$expected} '%s' placeholder(s)"
                );
            }
        }
    }

    /**
     * workflow_task/read is a GET endpoint that writes to the database, so the key it
     * receives has to be validated BEFORE it is stored, and the record has to be resolved
     * (which runs the permission global scope) BEFORE the write. Otherwise any logged-in
     * user can fill workflow_task_reads with rows for records they cannot even see.
     *
     * @return void
     */
    public function testReadValidatesTheKeyBeforeWriting()
    {
        $content = $this->read('src/Controllers/WorkflowTaskController.php');

        $this->assertStringContainsString(
            'WorkflowTaskService::parseTaskKey($key)',
            $content,
            'the key must go through the single shared validator'
        );

        $markPos = strpos($content, 'markSeen(');
        $checkPos = strpos($content, 'parseTaskKey($key)');
        $resolvePos = strpos($content, 'getValueModel(');

        $this->assertNotFalse($markPos);
        $this->assertNotFalse($checkPos);
        $this->assertNotFalse($resolvePos);
        $this->assertLessThan($markPos, $checkPos, 'the key must be validated before markSeen()');
        $this->assertLessThan($markPos, $resolvePos, 'the record must be resolved before markSeen()');
    }

    /**
     * The "seen" state is strictly personal. The model must carry the same per-user global
     * scope as NotifyNavbar, so a query that forgets the where() cannot leak or overwrite it.
     *
     * @return void
     */
    public function testSeenStateModelIsScopedToTheCurrentUser()
    {
        $content = $this->read('src/Model/WorkflowTaskRead.php');

        $this->assertStringContainsString("addGlobalScope('target_user'", $content);
        $this->assertStringContainsString("where('target_user_id', \\Exment::getUserId())", $content);
    }

    /**
     * The task list must hide workflows the rest of the product considers inactive.
     * Workflow::getWorkflowByTable() is the single source of truth (active_flg + active period
     * + setting_completed_flg); a raw active_flg query silently disagrees with it.
     *
     * @return void
     */
    public function testTaskListUsesTheSharedActiveWorkflowRule()
    {
        $content = $this->read('src/Services/Workflow/WorkflowTaskService.php');

        $this->assertStringContainsString('Workflow::getWorkflowByTable(', $content);
        $this->assertStringNotContainsString(
            "where('active_flg'",
            $content,
            'do not re-implement the active check: it drops the active period and setting_completed_flg'
        );
    }

    /**
     * Who gets the "same organization" notification must not depend on the permissions of
     * whoever pressed the button (the user table carries a visibility global scope).
     * The notification must also be switchable off, since its body carries the record label.
     *
     * @return void
     */
    public function testSameOrgNotifyIsDeterministicAndSwitchable()
    {
        $content = $this->read('src/Services/Notify/SameOrganizationWorkflowNotify.php');

        // the member list must not go through the user MODEL: CustomValueModelScope filters the
        // user table by the executer's own visibility, so the same organization would notify a
        // different set of colleagues depending on who pressed the button. It reads the pivot
        // table directly instead - which also makes it one query for every organization.
        $this->assertStringContainsString('CustomRelation::getRelationNameByTables(', $content);
        $this->assertStringNotContainsString(
            '$org->users',
            $content,
            'the scoped relation makes the member list depend on the executer'
        );
        // ... but a member who left the company must still be excluded
        $this->assertStringContainsString('deleted_at', $content, 'soft deleted members must not be notified');
        $this->assertStringContainsString("config('exment.same_org_workflow_notify'", $content);

        // notify_navbars.notify_id is INT UNSIGNED NOT NULL - a negative placeholder throws
        // on strict MySQL, after the workflow status has already been committed.
        $this->assertSame(
            0,
            preg_match_all('/notify_id\s*=\s*-\s*\d+/', $content),
            'notify_id must not be negative; use 0'
        );
    }

    /**
     * Both feature flags must exist in the shipped config, otherwise the only way to turn
     * the feature off is editing package code.
     *
     * @return void
     */
    public function testFeatureFlagsAreShippedInConfig()
    {
        $content = $this->read('config/exment.php');

        $this->assertStringContainsString("'workflow_task_navbar'", $content);
        $this->assertStringContainsString("'same_org_workflow_notify'", $content);
    }

    /**
     * The polling interval is operator-tunable through .env. Default 5 minutes, and a floor
     * so a typo cannot turn every logged-in browser into a request generator against an
     * endpoint that scans every pending record of every workflow table.
     *
     * @return void
     */
    public function testPollIntervalIsConfigurableAndClamped()
    {
        $original = config('exment.workflow_task_navbar_interval');

        try {
            $this->assertSame(300, WorkflowTaskNav::DEFAULT_INTERVAL, 'default must be 5 minutes');

            config(['exment.workflow_task_navbar_interval' => 900]);
            $this->assertSame(900, WorkflowTaskNav::interval(), 'a valid value must be used as-is');

            config(['exment.workflow_task_navbar_interval' => '600']);
            $this->assertSame(600, WorkflowTaskNav::interval(), '.env always yields strings');

            // "EXMENT_..._INTERVAL=" left empty makes config() return null (NOT the default),
            // and an unparsable value must not silently become the aggressive floor.
            foreach ([null, '', 'abc'] as $unusable) {
                config(['exment.workflow_task_navbar_interval' => $unusable]);
                $this->assertSame(
                    WorkflowTaskNav::DEFAULT_INTERVAL,
                    WorkflowTaskNav::interval(),
                    'an unusable value must fall back to the default, not the floor: ' . json_encode($unusable)
                );
            }

            foreach ([1, 0, -60] as $tooSmall) {
                config(['exment.workflow_task_navbar_interval' => $tooSmall]);
                $this->assertSame(
                    WorkflowTaskNav::MIN_INTERVAL,
                    WorkflowTaskNav::interval(),
                    'a too small value must be clamped, not obeyed: ' . json_encode($tooSmall)
                );
            }
        } finally {
            config(['exment.workflow_task_navbar_interval' => $original]);
        }
    }

    /**
     * The value has to actually reach the browser, and the javascript must keep its own floor
     * (the page source is editable by whoever is looking at it).
     *
     * @return void
     */
    public function testPollIntervalReachesTheJavascript()
    {
        $nav = $this->read('src/Form/Navbar/WorkflowTaskNav.php');
        $js = $this->read('public/vendor/exment/js/workflow_task_navbar.js');

        $this->assertStringContainsString('workflow_task_navbar_interval', $nav, 'the nav must render the interval');
        $this->assertStringContainsString("\$('#workflow_task_navbar_interval')", $js, 'the js must read it');
        $this->assertStringNotContainsString(
            ', 60000)',
            $js,
            'the interval must come from the config, not be hard coded'
        );

        // the js floor must not be looser than the server side floor
        if (preg_match('/seconds\s*<\s*(\d+)/', $js, $m) !== 1) {
            $this->fail('cannot find the js floor');
        }
        $this->assertGreaterThanOrEqual(WorkflowTaskNav::MIN_INTERVAL, (int)$m[1]);
    }

    /**
     * The migration must create the table the model reads, with the unique pair that
     * makes markSeen() idempotent.
     *
     * @return void
     */
    public function testMigrationMatchesModel()
    {
        $migration = $this->read('database/migrations/2026_07_01_000000_create_workflow_task_reads_table.php');

        $this->assertStringContainsString("create('workflow_task_reads'", $migration);

        // the badge filter is target_user_id = ? AND custom_table_id = ? AND morph_id = <record>,
        // in this column order - the unique index has to be able to serve it
        $this->assertStringContainsString(
            "unique(['target_user_id', 'custom_table_id', 'morph_id']",
            $migration,
            'the unique index doubles as the lookup index of the navbar count'
        );

        // WorkflowAction clears the rows of one record for ALL users, so it cannot use the
        // index above (it does not know target_user_id) and needs its own
        $this->assertStringContainsString(
            "index(['custom_table_id', 'morph_id']",
            $migration,
            'the delete on status change would be a full table scan without this index'
        );

        $this->assertStringNotContainsString(
            'task_key',
            $migration,
            'the seen state is stored as integer columns; a composed string cannot be filtered in SQL'
        );

        $model = new \Exceedone\Exment\Model\WorkflowTaskRead();
        $this->assertSame(
            'workflow_task_reads',
            $model->getTable(),
            'model table name drifted away from the migration'
        );
    }

    /**
     * The navbar endpoint runs on a timer in every open browser. It must ask for a count and
     * for the few rows it shows - never for the whole task list, which is what the full list
     * screen (and only it) needs.
     *
     * @return void
     */
    public function testNavbarEndpointDoesNotLoadEveryTask()
    {
        $content = $this->read('src/Controllers/ApiController.php');

        $start = strpos($content, 'public function workflowTaskPage');
        $this->assertNotFalse($start, 'ApiController::workflowTaskPage() is gone');
        $end = strpos($content, 'public function ', $start + 10);
        $method = substr($content, $start, $end === false ? null : $end - $start);

        $this->assertStringContainsString('countUnseen()', $method, 'the badge counts the unseen tasks');
        $this->assertStringContainsString('topPending(', $method, 'the dropdown lists the pending tasks');
        $this->assertStringNotContainsString(
            'getTasks',
            $method,
            'the navbar must not read the full task list to count it and then take(5)'
        );
    }

    /**
     * The badge and the dropdown list answer two different questions: "what have I not opened
     * yet" and "what still needs an action". Deriving the list from the badge is what made
     * "mark all as seen" print "there is no un-actioned task" while the list screen still
     * showed every one of them.
     *
     * @return void
     */
    public function testMarkAllSeenEmptiesTheBadgeNotTheList()
    {
        $content = $this->read('src/Controllers/ApiController.php');

        $start = strpos($content, 'public function workflowTaskPage');
        $this->assertNotFalse($start);
        $end = strpos($content, 'public function ', $start + 10);
        $method = substr($content, $start, $end === false ? null : $end - $start);

        // the items must not be behind an "is anything unseen" gate
        $this->assertStringNotContainsString(
            '$count < 1',
            $method,
            'an empty item list when nothing is unseen makes the dropdown claim there is no task'
        );
        $this->assertStringNotContainsString(
            'topUnseen(',
            $method,
            'the dropdown lists pending tasks; only the badge is about the unseen ones'
        );

        // ... and the javascript must decide the same way: the "no task" line belongs to an
        // empty item list, not to a zero badge
        $js = $this->read('public/vendor/exment/js/workflow_task_navbar.js');

        $this->assertStringContainsString(
            'if (hasValue(data.items)) {',
            $js,
            'the list branch must be driven by the items, not by the badge count'
        );
        $this->assertSame(
            1,
            substr_count($js, 'data.count > 0'),
            'the badge count may only decide whether the badge is drawn'
        );

        $itemsBranch = strpos($js, 'if (hasValue(data.items)) {');
        $noItemLine = strpos($js, 'workflow_task_navbar_noitem');
        $this->assertNotFalse($noItemLine);
        $this->assertLessThan(
            $noItemLine,
            $itemsBranch,
            'the "no un-actioned task" line must be the else of the item list'
        );

        // seen and unseen tasks are listed together, so the unseen ones stay recognisable
        $this->assertStringContainsString(
            "'style': d.seen ? null : 'font-weight:bold;'",
            $js,
            'without a marker the badge number cannot be explained by the list'
        );
        $this->assertStringContainsString(
            "'seen' =>",
            $method,
            'the endpoint has to ship the seen flag the dropdown renders'
        );
    }

    /**
     * The "not seen yet" filter and the row limit must live in SQL. Doing either in PHP means
     * every pending record of every workflow table is fetched and hydrated on every poll.
     *
     * @return void
     */
    public function testUnseenFilterAndLimitAreDoneInSql()
    {
        $content = $this->read('src/Services/Workflow/WorkflowTaskService.php');

        $this->assertStringContainsString('whereNotExists(', $content, 'the seen rows must be excluded in SQL');
        $this->assertStringContainsString('->limit($limit)', $content, 'the dropdown must limit per table');

        // countUnseen() must not walk the list; it only sums the per-table COUNTs
        $start = strpos($content, 'public function countUnseen(');
        $this->assertNotFalse($start);
        $end = strpos($content, 'public function topUnseen(', $start);
        $method = substr($content, $start, $end === false ? null : $end - $start);

        $this->assertStringContainsString('countUnseenPerTable()', $method);
        $this->assertStringNotContainsString('getTasks', $method, 'the badge must never build the task list');

        // and the per-table count must be a COUNT, not a get()->count()
        $countStart = strpos($content, 'private function countUnseenPerTable(');
        if ($countStart === false) {
            $this->fail('countUnseenPerTable() must exist in the service');
        }
        $countEnd = strpos($content, 'public function countUnseen(', $countStart);
        $countMethod = substr($content, $countStart, $countEnd === false ? null : $countEnd - $countStart);

        $this->assertStringContainsString('->count(', $countMethod);
        $this->assertStringNotContainsString('->get()', $countMethod, 'no row may leave the database just to be counted');
    }

    /**
     * The status is not part of the task_key any more, so something else has to make a task
     * unseen again when it moves on: the status change itself clears the read rows - for every
     * user, not only for the one who pressed the button.
     *
     * @return void
     */
    public function testStatusChangeClearsTheSeenState()
    {
        $content = $this->read('src/Model/WorkflowAction.php');

        $this->assertStringContainsString(
            'WorkflowTaskRead::withoutGlobalScopes()',
            $content,
            'WorkflowTaskRead is scoped to the login user; the marks of ALL users must be cleared'
        );

        $createPos = strpos($content, 'WorkflowValue::create($createData)');
        $deletePos = strpos($content, 'WorkflowTaskRead::withoutGlobalScopes()');

        $this->assertNotFalse($createPos, 'the workflow value creation moved; check the clear-up still runs with it');
        $this->assertNotFalse($deletePos);
        $this->assertGreaterThan(
            $createPos,
            $deletePos,
            'the seen state must be cleared where the status actually changes'
        );
    }

    /**
     * The other half of the clean-up: a hard deleted record must take its marks with it,
     * otherwise workflow_task_reads grows for ever with rows pointing at nothing.
     * It has to sit in deleteRelationValues(), which only runs on a FORCE delete - a soft
     * delete keeps the marks so a restore keeps its state.
     *
     * @return void
     */
    public function testHardDeleteClearsTheSeenState()
    {
        $content = $this->read('src/Model/CustomValue.php');

        $methodPos = strpos($content, 'protected function deleteRelationValues()');
        $deletePos = strpos($content, 'WorkflowTaskRead::withoutGlobalScopes()');

        $this->assertNotFalse($methodPos, 'deleteRelationValues() moved; check the clean-up still runs on hard delete');
        $this->assertNotFalse($deletePos, 'a hard deleted record must drop its rows in workflow_task_reads');
        $this->assertGreaterThan(
            $methodPos,
            $deletePos,
            'the clean-up must be inside deleteRelationValues(), so a soft delete keeps the seen state'
        );
    }

    /**
     * The badge polls on every page for every user, and building one work-user query costs
     * about as much as running it. Tables the user cannot read at all must be dropped before
     * any query is built - and that filter must mirror CustomValueModelScope, including the
     * 1:N child that inherits its parent's permission.
     *
     * @return void
     */
    public function testTablesWithoutPermissionAreSkippedEarly()
    {
        $content = $this->read('src/Services/Workflow/WorkflowTaskService.php');

        $this->assertStringContainsString(
            'Permission::AVAILABLE_ACCESS_CUSTOM_VALUE',
            $content,
            'use the same permission set as CustomValueModelScope'
        );
        $this->assertStringContainsString(
            'inherit_parent_permission',
            $content,
            'a 1:N child readable only through its parent must not be dropped'
        );

        // the filter has to run in workflowCustomTables(), before pendingQuery() is ever called
        $filterPos = strpos($content, '$this->hasAnyAccess($custom_table)');
        $buildPos = strpos($content, 'private function pendingQuery');
        $this->assertNotFalse($filterPos, 'hasAnyAccess() is defined but never applied');
        $this->assertNotFalse($buildPos);
        $this->assertLessThan($buildPos, $filterPos, 'the permission filter must run before any query is built');
    }

    /**
     * A login user can outlive its user record. The navbar runs on every admin page, so that
     * must be an empty list, not a fatal error in the work-user conditions.
     *
     * @return void
     */
    public function testMissingBaseUserIsGuarded()
    {
        $content = $this->read('src/Services/Workflow/WorkflowTaskService.php');

        $this->assertStringContainsString('private function hasBaseUser()', $content);
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($content, '!$this->hasBaseUser()'),
            'both the read path (workflowCustomTables) and the write path (markSeen) need the guard'
        );

        // pin the paths themselves, not how many of them there are
        foreach (['private function workflowCustomTables', 'public function markSeen'] as $needle) {
            $start = strpos($content, $needle);
            $this->assertNotFalse($start, $needle . '() is gone');
            $end = strpos($content, "\n    }", $start);
            $this->assertStringContainsString(
                '!$this->hasBaseUser()',
                substr($content, $start, $end === false ? null : $end - $start),
                $needle . '() lost its guard'
            );
        }
    }

    /**
     * markSeen() is called with every pending task at once by "mark all as seen".
     * It must stay at a fixed number of queries, not one per task.
     *
     * @return void
     */
    public function testMarkSeenIsBulk()
    {
        $content = $this->read('src/Services/Workflow/WorkflowTaskService.php');

        $start = strpos($content, 'public function markSeen(');
        $this->assertNotFalse($start);
        $end = strpos($content, 'public function markAllSeen(', $start);
        $method = substr($content, $start, $end === false ? null : $end - $start);

        $this->assertStringContainsString(
            'WorkflowTaskRead::insert($chunk)',
            $method,
            'the missing rows must go in with one INSERT per chunk'
        );
        // "mark all as seen" can hand over every pending record of the installation; one INSERT
        // carrying all of them would exceed max_allowed_packet and fail as a whole
        $this->assertStringContainsString(
            'array_chunk($inserts, 1000)',
            $method,
            'the bulk INSERT must be chunked'
        );
        // and the "which of these are already stored" lookup must be the bounded one
        $this->assertStringContainsString(
            '$this->seenSet($byTable)',
            $method,
            'the lookup must be limited to the keys being marked'
        );

        // firstOrCreate() may only appear in the unique-violation fallback, after the bulk insert
        $insertPos = strpos($method, 'WorkflowTaskRead::insert($chunk)');
        $firstOrCreatePos = strpos($method, 'firstOrCreate(');
        if ($firstOrCreatePos !== false) {
            $this->assertGreaterThan(
                $insertPos,
                $firstOrCreatePos,
                'firstOrCreate() per key is two queries per task; it is only the conflict fallback'
            );
        }
    }

    /**
     * The list screen must not read every pending record of every workflow table and then cut
     * 20 rows out of it in PHP. It asks for one page, and for the two totals as COUNTs.
     *
     * @return void
     */
    public function testListScreenAsksForOnePage()
    {
        $content = $this->read('src/Controllers/WorkflowTaskController.php');

        $start = strpos($content, 'public function index(');
        $this->assertNotFalse($start, 'WorkflowTaskController::index() is gone');
        $end = strpos($content, 'public function read(', $start);
        $method = substr($content, $start, $end === false ? null : $end - $start);

        $this->assertStringContainsString('getPage(', $method, 'the screen must ask for a single page');
        $this->assertStringNotContainsString(
            'getTasksWithSeen(',
            $method,
            'that builds every pending task of every table just to show 20 rows'
        );
        // "?per_page=1000000" decides how many rows the service is asked to read
        $this->assertStringContainsString(
            'in_array($perPage, $perPageOptions, true)',
            $method,
            'per_page comes from the query string and must be limited to the offered values'
        );
    }

    /**
     * The seen state is looked up for the rows on screen, not for the whole reading history of
     * the account - that table only ever grows.
     *
     * @return void
     */
    public function testSeenLookupIsBoundedToTheDisplayedRows()
    {
        $content = $this->read('src/Services/Workflow/WorkflowTaskService.php');

        $this->assertStringContainsString('private function seenSet(array $byTable)', $content);
        $this->assertStringContainsString(
            'private static function idChunks(',
            $content,
            'the IN list of the lookup must be chunked'
        );

        $start = strpos($content, 'public function getTasksWithSeen(');
        $this->assertNotFalse($start);
        $end = strpos($content, "\n    }", $start);
        $method = substr($content, $start, $end === false ? null : $end - $start);

        $this->assertStringContainsString('$this->seenSet($byTable)', $method);
        $this->assertStringNotContainsString(
            'seenKeys()',
            $method,
            'seenKeys() reads every row the user ever marked; the screen only needs the rows it shows'
        );
    }

    /**
     * Building ONE work-user query costs about as much as running it, and the list screen needs
     * the same query three times (total, page, unseen count). It must be built once and cloned.
     *
     * @return void
     */
    public function testWorkUserQueryIsBuiltOncePerTable()
    {
        $content = $this->read('src/Services/Workflow/WorkflowTaskService.php');

        $this->assertStringContainsString('private function baseQuery(CustomTable $custom_table)', $content);
        $this->assertStringContainsString('clone $this->baseQuery($custom_table)', $content);
        // the call itself, not the mentions of it in the comments
        $this->assertSame(
            1,
            substr_count($content, 'RelationTable::setWorkflowWorkUsersSubQuery($query'),
            'the work-user conditions must be built in exactly one place'
        );
    }

    /**
     * The navbar dropdown must build a model only for the rows it shows, not for $limit rows
     * per workflow table. It runs on a timer in every open browser.
     *
     * @return void
     */
    public function testNavbarDropdownHydratesOnlyWhatItShows()
    {
        $content = $this->read('src/Services/Workflow/WorkflowTaskService.php');

        // topUnseen() (badge) and topPending() (list) share one reader
        $start = strpos($content, 'private function topRows(');
        $this->assertNotFalse($start, 'the shared dropdown reader is gone');
        $end = strpos($content, "\n    }", $start);
        $method = substr($content, $start, $end === false ? null : $end - $start);

        $this->assertStringContainsString('->limit($limit)', $method, 'the dropdown must limit per table');
        $this->assertStringContainsString('->toBase()', $method, 'the candidates must not be hydrated');
        $this->assertStringContainsString('$this->buildRows(', $method, 'only the rows on screen become a model');

        // and both entry points must go through it, so neither can drift into a full read
        foreach (['public function topUnseen(', 'public function topPending('] as $needle) {
            $entry = strpos($content, $needle);
            $this->assertNotFalse($entry, $needle . ') is gone');
            $entryEnd = strpos($content, "\n    }", $entry);
            $this->assertStringContainsString(
                '$this->topRows(',
                substr($content, $entry, $entryEnd === false ? null : $entryEnd - $entry),
                $needle . ') stopped using the shared reader'
            );
        }
    }

    /**
     * One notification row per colleague, but not one INSERT per colleague: this runs inside
     * the request that executes the workflow action.
     *
     * @return void
     */
    public function testNotifyWritesInBulk()
    {
        $content = $this->read('src/Services/Notify/SameOrganizationWorkflowNotify.php');

        $start = strpos($content, 'public static function notify(');
        $this->assertNotFalse($start);
        $method = substr($content, $start);

        $this->assertStringContainsString('NotifyNavbar::insert($chunk)', $method);
        $this->assertStringContainsString('array_chunk($rows, 500)', $method);
        $this->assertStringNotContainsString(
            '$notify_navbar->save()',
            $method,
            'one save() per member is one INSERT per member'
        );
        // insert() skips the model events, so these columns have to be written by hand
        foreach (['created_at', 'created_user_id', 'trigger_user_id'] as $column) {
            $this->assertStringContainsString($column, $method, $column . ' is not filled by a bulk insert');
        }
    }

    /**
     * The javascript payloads of this view must not go out on json_encode()'s defaults, and
     * the view must not contain anything blade or the HTML parser reads as CODE where a human
     * reads a comment. All of these bite only when the page is opened, never at edit time:
     *   - an empty blade echo compiles to echo e( )
     *   - a directive name without its parentheses compiles to json_encode(, 15, 512)
     *   - a closing script tag ends the script element wherever it appears, comment included
     *
     * @return void
     */
    public function testViewPayloadsAreHardenedAndNothingHidesInAComment()
    {
        $blade = $this->read('resources/views/workflow_task/index.blade.php');

        $this->assertStringContainsString('var tableUrls = {!! json_encode($tableUrls,', $blade);

        // Both payloads name the flags by hand, and both must keep naming them. Blade's
        // own directive is not an option for the lang array - it compiles by splitting its
        // argument on every comma (value, flags, depth), so an inline array literal loses
        // everything after its first comma and the view stops compiling - so this counts
        // TWO occurrences: one of them silently reverting to json_encode()'s defaults is
        // exactly the regression worth catching.
        $this->assertSame(
            2,
            substr_count($blade, 'JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT'),
            'every json payload in this view must carry the flags the directive would apply'
        );

        $this->assertStringNotContainsString(
            '{{ }}',
            $blade,
            'an empty blade echo compiles to echo e( ) and 500s the page'
        );
        $this->assertSame(
            1,
            substr_count($blade, '</script'),
            'only the real closing tag may appear - the HTML parser ends the script element '
            . 'at that sequence even inside a javascript comment'
        );
        $this->assertSame(
            0,
            preg_match('/(?<!\\w)@json(?!\\s*\\()/', $blade),
            'a directive name without its parentheses compiles to json_encode(, 15, 512)'
        );
    }

    /**
     * fit() decides whether the INSERT fits the column, so assert what it returns, not that
     * it is called. The first version of it appended a 3 character marker on top of the
     * budget and handed back $limit + 2 characters - a cap that does not cap.
     *
     * @return void
     */
    public function testFitNeverExceedsTheLimit()
    {
        $fit = new \ReflectionMethod(
            \Exceedone\Exment\Services\Notify\SameOrganizationWorkflowNotify::class,
            'fit'
        );
        $fit->setAccessible(true);

        foreach ([200, 300, 2000] as $limit) {
            foreach ([
                'ascii'      => str_repeat('a', $limit * 3),
                'multibyte'  => str_repeat('\u{65e5}\u{672c}\u{8a9e}', $limit),
                'at limit'   => str_repeat('a', $limit),
                'under'      => 'short value',
            ] as $name => $value) {
                $got = $fit->invoke(null, $value, $limit);

                $this->assertLessThanOrEqual(
                    $limit,
                    mb_strlen($got),
                    "fit({$name}, {$limit}) returned " . mb_strlen($got) . ' characters'
                );
                $this->assertTrue(mb_check_encoding($got, 'UTF-8'), 'fit() cut a character in half');
            }
        }

        // nothing is dropped when nothing has to be
        $this->assertSame('hello', $fit->invoke(null, 'hello', 200));
        $this->assertSame('', $fit->invoke(null, null, 200));
        // ...and when something is, it says so
        $this->assertStringEndsWith('...', $fit->invoke(null, str_repeat('a', 500), 200));
    }

    /**
     * The notification sentence is built with vsprintf() (exmtrans()), which throws
     * ValueError in PHP 8 when the format string wants more %s than it is given.
     * testPlaceholderCountMatchesBetweenLocales() pins the SHIPPED translations to 4; this
     * pins the other end of the contract - the number of arguments the call site passes.
     *
     * @return void
     */
    public function testNotifyBodyPassesOneArgumentPerPlaceholder()
    {
        // the package ships CRLF, so normalise before any newline-sensitive parsing -
        // "exmtrans(\n" simply never matches "exmtrans(\r\n"
        $content = str_replace("\r\n", "\n", $this->read('src/Services/Notify/SameOrganizationWorkflowNotify.php'));

        $start = strpos($content, "'workflow.same_org_notify.body',");
        $this->assertNotFalse($start, 'the body translation key is gone');

        // the argument list runs from the key line to the line that closes exmtrans()
        $end = strpos($content, "\n            )", $start);
        $this->assertNotFalse($end, 'exmtrans() no longer closes on its own line');

        // one line per argument after the key line, ignoring comments and blank lines
        $args = 0;
        foreach (array_slice(explode("\n", substr($content, $start, $end - $start)), 1) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '//') === 0) {
                continue;
            }
            $args++;
        }

        $this->assertSame(
            4,
            $args,
            'the body format string has 4 %s placeholders, so exactly 4 arguments may be passed'
        );
    }

    /**
     * notify_navbars.notify_subject is varchar(200) and notify_body varchar(2000), and the
     * body carries a record label of unbounded length. This INSERT runs AFTER the workflow
     * status was committed, so an overflow is either a 500 on a successful action (strict
     * MySQL) or silent data loss - neither may be left to the database.
     *
     * @return void
     */
    public function testNotifyFitsTheColumnLimits()
    {
        $content = $this->read('src/Services/Notify/SameOrganizationWorkflowNotify.php');

        foreach (['MAX_SUBJECT_LENGTH = 200', 'MAX_BODY_LENGTH = 2000'] as $const) {
            $this->assertStringContainsString($const, $content, $const . ' must match the column');
        }

        $start = strpos($content, 'public static function notify(');
        $this->assertNotFalse($start);
        $method = substr($content, $start);

        // "::NAME" and not "static::NAME": what has to hold here is that each cap is applied.
        // Which resolution keyword reaches a private constant is a different question, and
        // these are private - self:: is the correct one, static:: a latent fatal in a subclass.
        $this->assertStringContainsString('::MAX_SUBJECT_LENGTH', $method, 'the subject is not capped');
        $this->assertStringContainsString('::MAX_BODY_LENGTH', $method, 'the body is not capped');
        $this->assertStringContainsString('::MAX_LABEL_LENGTH', $method, 'the record label is not capped');
    }

    /**
     * Neither half of this notification may break the workflow action it reports on.
     *
     * getOtherOrgMemberIds() runs BEFORE the transaction, so anything it throws refuses an
     * action that is perfectly valid. notify() runs AFTER it, so anything IT throws answers
     * 500 for a status change that is already committed.
     *
     * @return void
     */
    public function testNotifyNeverBreaksTheWorkflowAction()
    {
        $content = $this->read('src/Services/Notify/SameOrganizationWorkflowNotify.php');

        foreach (['getOtherOrgMemberIds(', 'notify('] as $name) {
            $start = strpos($content, 'public static function ' . $name);
            $this->assertNotFalse($start, $name . ' is gone');
            $end = strpos($content, "\n    }", $start);
            $method = substr($content, $start, $end === false ? null : $end - $start);

            $this->assertStringContainsString(
                'catch (\\Throwable',
                $method,
                $name . ' must not be able to break the action it only reports on'
            );
        }
    }
}
