<?php

namespace Exceedone\Exment\Controllers;

/**
 *
 * @static string $node_key
 * @method array getDirectoryPaths($folder)
 * @method array getFilePaths($folder)
 */
trait CodeTreeTrait
{
    /**
     * Get and set file and directory nodes in target folder
     *
     * @param string $folder
     * @param string $parent
     * @param array $json
     * @param bool $root is root as.
     */
    protected function setDirectoryNodes($folder, $parent, &$json, bool $root = false)
    {
        $directory_node = "node_" . make_uuid();
        $json[] = [
            'id' => $directory_node,
            'parent' => $parent,
            'path' => $folder,
            'text' => isMatchString($folder, '/') ? '/' : basename($folder),
            'state' => [
                'opened' => $parent == '#',
                'selected' => $root
            ]
        ];

        $directories = $this->getDirectoryPaths($folder);
        foreach ($directories as $directory) {
            $this->setDirectoryNodes($directory, $directory_node, $json, false);
        }

        $files = $this->getFilePaths($folder);
        foreach ($files as $file) {
            $json[] = [
                'id' => "node_" . make_uuid(),
                'parent' => $directory_node,
                'path' => path_join($folder, basename($file)),
                'icon' => 'jstree-file',
                'text' => basename($file),
            ];
        }
    }


    /**
     * Get node path from node id
     *
     * @param string $nodeId
     * @return string|null
     */
    protected function getNodePath($nodeId): ?string
    {
        $nodelist = session(static::node_key);
        if (is_nullorempty($nodelist)) {
            return null;
        }

        foreach ($nodelist as $node) {
            if (!isMatchString($nodeId, array_get($node, 'id'))) {
                continue;
            }

            return str_replace('//', '/', array_get($node, 'path'));
        }

        throw new \Exception();
    }
}
