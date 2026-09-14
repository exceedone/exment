<?php

namespace Exceedone\Exment\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Exceedone\Exment\Database\Query\Grammars\MySqlGrammar;

class MySqlGrammarTest extends TestCase
{
    /**
     * @return void
     */
    public function testWhereInArrayColumnCastsTheMatchColumnToText()
    {
        $builder = new class() {
            public string $sql = '';

            public function whereRaw(string $sql)
            {
                $this->sql = $sql;

                return $this;
            }
        };

        $grammar = new MySqlGrammar();
        $grammar->whereInArrayColumn($builder, 'items', 'source.code', 'target.codes');

        $this->assertSame(
            'FIND_IN_SET(CAST(`source`.`code` AS CHAR), REPLACE(REPLACE(REPLACE(REPLACE(`target`.`codes`, \'[\', \'\'), \' \', \'\'), \']\', \'\'), \'\\"\', \'\'))',
            $builder->sql
        );
    }
}
