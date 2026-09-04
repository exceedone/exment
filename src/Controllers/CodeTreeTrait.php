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
    // @phpstan-ignore-next-line
    protected function setDirectoryNodes($folder, $parent, &$json, bool $root = false)
    {
        /** @phpstan-ignore-next-line */
        $directory_node = "node_" . make_uuid();
        $json[] = [
            'id' => $directory_node,
            'parent' => $parent,
            'path' => $folder,
            /** @phpstan-ignore-next-line */
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
                /** @phpstan-ignore-next-line */
                'id' => "node_" . make_uuid(),
                'parent' => $directory_node,
                /** @phpstan-ignore-next-line */
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
        /** @phpstan-ignore-next-line */
        if (is_nullorempty($nodelist)) {
            return null;
        }

        foreach ($nodelist as $node) {
            /** @phpstan-ignore-next-line */
            if (!isMatchString($nodeId, array_get($node, 'id'))) {
                continue;
            }

            return str_replace('//', '/', array_get($node, 'path'));
        }

        throw new \Exception();
    }
}
