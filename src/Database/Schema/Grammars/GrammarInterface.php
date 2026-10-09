<?php

namespace Exceedone\Exment\Database\Schema\Grammars;

use Exceedone\Exment\Model\CustomColumn;

interface GrammarInterface
{
    /**
     * Compile the query to get version
     *
     * @return string
     */
    public function compileGetVersion();

    /**
     * Compile the query to show tables
     *
     * @return string
     */
    public function compileGetTableListing();

    /**
     * Compile the query to get column difinitions
     *
     * @return string
     */
    // @phpstan-ignore-next-line
    public function compileColumnDefinitions($tableName);

    /**
     * Compile the query to Create Value Table
     *
     * @return string
     */
    public function compileCreateValueTable(string $tableName);

    /**
     * Compile the query to determine the list of tables.
     *
     * @return string
     */
    public function compileCreateRelationValueTable(string $tableName);

    // @phpstan-ignore-next-line
    public function compileAlterIndexColumn($db_table_name, $db_column_name, $index_name, $json_column_name, CustomColumn $custom_column);

    // @phpstan-ignore-next-line
    public function compileGetIndex($tableName);

    // @phpstan-ignore-next-line
    public function compileGetUnique($tableName);
}
