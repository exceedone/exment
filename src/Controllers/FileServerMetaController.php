<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Model\FileDataLink;
use Exceedone\Exment\Model\FileWorkspace;
use Exceedone\Exment\Services\FileServer\DataLinks;
use Exceedone\Exment\Services\FileServer\FileSearch;
use Exceedone\Exment\Services\FileServer\FileServerException;
use Exceedone\Exment\Services\FileServer\Metadata;
use ExmentAdminCore\Admin\Layout\Content;
use Illuminate\Http\Request;

/**
 * File server, phase 4 (ISS00378): the columns of a workspace and their
 * values, the links of the folders and files to the data of Exment, and the
 * search of the folders and files.
 */
class FileServerMetaController extends FileServerController
{
    // ================================================================ the search

    /**
     * The search of the folders and files the user may see: by the name, the
     * values of the columns and the text in the files.
     *
     * @return Content
     */
    public function search(Request $request, Content $content)
    {
        $q = mb_substr(trim(strval($request->get('q', ''))), 0, 200);
        $workspaceId = $request->filled('workspace') ? intval($request->get('workspace')) : null;
        $library = $this->library();
        $result = (new FileSearch($library))->search($q, $workspaceId);

        $rows = $this->nodeRows(array_map(function ($row) {
            return $row['node'];
        }, $result['rows']));
        foreach ($result['rows'] as $i => $row) {
            if (isset($rows[$i])) {
                $rows[$i]['snippet'] = $row['snippet'];
                $rows[$i]['in_content'] = $row['in_content'];
                $rows[$i]['path'] = $this->pathOf($row['node'], $library->access());
            }
        }

        $this->setPageInfo(exmtrans('file_server.header'), exmtrans('file_server.search'), exmtrans('file_server.search_description'), 'fa-search');
        $this->AdminContent($content);
        $content->row(view('exment::file_server.search', [
            'q' => $q,
            'workspace_id' => $workspaceId,
            'workspaces' => $library->access()->workspaces()->map(function (FileWorkspace $ws) {
                return ['id' => $ws->id, 'name' => $ws->name];
            })->all(),
            'rows' => $rows,
            'engine' => $result['engine'],
            'action' => admin_urls('file_server', 'search'),
        ]));
        return $content;
    }

    /**
     * The folders above a node: "workspace / folder / folder".
     */
    protected function pathOf(\Exceedone\Exment\Model\FileNode $node, \Exceedone\Exment\Services\FileServer\Access $access): string
    {
        $names = [];
        $workspace = $node->workspace;
        if (isset($workspace)) {
            $names[] = $workspace->name;
        }
        $list = $node->ancestorsAndSelf();
        array_pop($list);
        foreach ($list as $item) {
            // a folder above that the user may not see: not its name (ISS00433)
            $names[] = isset($workspace) && $access->can($workspace, $item, \Exceedone\Exment\Services\FileServer\Level::VIEW)
                ? $item->name : strval(exmtrans('file_server.path_hidden'));
        }
        return implode(' / ', $names);
    }

    // ================================================================ the columns

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function saveFields(Request $request, string $workspace)
    {
        return $this->run(function () use ($request, $workspace) {
            $ws = $this->workspace($workspace);
            $entries = $request->get('fields', []);
            if (is_string($entries)) {
                $entries = json_decode($entries, true);
            }
            if (!is_array($entries)) {
                throw new FileServerException(exmtrans('file_server.error_upload'));
            }
            (new Metadata($this->library()))->saveFields($ws, array_values(array_filter($entries, 'is_array')));
            return ['message' => exmtrans('common.message.success_execute')];
        });
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function saveValues(Request $request, string $workspace, string $node)
    {
        return $this->nodeAction($workspace, $node, function ($ws, $item) use ($request) {
            $input = (array)$request->get('meta', []);
            // a multiselect with nothing chosen is not sent by the form
            foreach ((array)$request->get('meta_present', []) as $name) {
                if (is_string($name) && !array_key_exists($name, $input)) {
                    $input[$name] = null;
                }
            }
            (new Metadata($this->library()))->setValues($ws, $item, $input);
            return ['message' => exmtrans('common.message.success_execute')];
        });
    }

    // ================================================================ the links to the data

    /**
     * The data of a table for the picker: [{id, label}].
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function dataLookup(Request $request)
    {
        return $this->run(function () use ($request) {
            return ['items' => DataLinks::lookup(strval($request->get('table', '')), strval($request->get('q', '')))];
        });
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function addDataLink(Request $request, string $workspace, string $node)
    {
        return $this->nodeAction($workspace, $node, function ($ws, $item) use ($request) {
            (new DataLinks($this->library()))->add($ws, $item, strval($request->get('table', '')), intval($request->get('data_id', 0)));
            return ['message' => exmtrans('common.message.success_execute')];
        });
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function removeDataLink(Request $request, string $workspace, string $node, string $link)
    {
        return $this->nodeAction($workspace, $node, function ($ws, $item) use ($link) {
            $row = FileDataLink::find(intval($link));
            if (!isset($row)) {
                throw FileServerException::notFound();
            }
            (new DataLinks($this->library()))->remove($ws, $item, $row);
            return ['message' => exmtrans('common.message.success_execute')];
        });
    }
}
