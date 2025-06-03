<?php

namespace Exceedone\Exment\Tests\Unit;

use Illuminate\Validation\ValidationException;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Tests\TestDefine;

class CustomColumnValidationTest extends UnitTestBase
{
    use CustomColumnTrait;

    // required, unneccesarry, etc ----------------------------------------------------

    /**
     * @return void
     */
    public function testSuccess()
    {
        $this->executeTestEdit([
            'text' => 'abc',
        ], [
        ]);
    }

    /**
     * @return void
     */
    public function testRequired()
    {
        $this->executeTestEdit([
        ], [
            'text' => [$this->getErrorMessage('required', 'text')],
        ]);
    }

    /**
     * Update check,. It's Success
     *
     * @return void
     */
    public function testRequiredUpdate()
    {
        $this->executeTestEdit([
        ], [], [], 1);
    }

    /**
     * @return void
     */
    public function testUnnecessary()
    {
        $this->executeTestEdit([
            'xyz' => 'xyz',
            'text' => 'text',
        ], [
            'xyz' => [exmtrans('error.not_contains_column')],
        ]);
    }

    // Text ----------------------------------------------------

    /**
     * @return void
     */
    public function testSuccessText()
    {
        $this->executeTestAllColumns(ColumnType::TEXT, [
            ColumnType::TEXT => 'abc',
        ]);
    }

    /**
     * @return void
     */
    public function testSuccessText2()
    {
        $this->executeTestAllColumns(ColumnType::TEXT, [
            ColumnType::TEXT => 1,
        ]);
    }

    /**
     * @return void
     */
    public function testTextNotString()
    {
        $this->executeTestAllColumns(ColumnType::TEXT, [
            ColumnType::TEXT => ['abc'],
        ], [
            ColumnType::TEXT => [$this->getErrorMessage('string', ColumnType::TEXT)],
        ]);
    }



    // Textarea ----------------------------------------------------

    /**
     * @return void
     */
    public function testSuccessTextarea()
    {
        $this->executeTestAllColumns(ColumnType::TEXTAREA, [
            ColumnType::TEXTAREA => "abc\r\ndef",
        ]);
    }

    /**
     * @return void
     */
    public function testSuccessTextarea2()
    {
        $this->executeTestAllColumns(ColumnType::TEXTAREA, [
            ColumnType::TEXTAREA => 1,
        ]);
    }


    // Textarea ----------------------------------------------------

    /**
     * @return void
     */
    public function testSuccessEditor()
    {
        $this->executeTestAllColumns(ColumnType::EDITOR, [
            ColumnType::EDITOR => "abc\r\ndef",
        ]);
    }

    /**
     * @return void
     */
    public function testSuccessEditor2()
    {
        $this->executeTestAllColumns(ColumnType::EDITOR, [
            ColumnType::EDITOR => 1,
        ]);
    }


    // Url ----------------------------------------------------

    /**
     * @return void
     */
    public function testSuccessUrl()
    {
        $this->executeTestAllColumns(ColumnType::URL, [
            ColumnType::URL => "https://github.com/exceedone/exment",
        ]);
    }

    /**
     * @return void
     */
    public function testUrlNotString()
    {
        $this->executeTestAllColumns(ColumnType::URL, [
            ColumnType::URL => ['https://github.com/exceedone/exment'],
        ], [
            ColumnType::URL => [$this->getErrorMessage('url', ColumnType::URL)],
        ]);
    }

    /**
     * @return void
     */
    public function testUrlNotString2()
    {
        $this->executeTestAllColumns(ColumnType::URL, [
            ColumnType::URL => 1,
        ], [
            ColumnType::URL => [$this->getErrorMessage('url', ColumnType::URL)],
        ]);
    }

    /**
     * @return void
     */
    public function testUrlNotUrl()
    {
        $this->executeTestAllColumns(ColumnType::URL, [
            ColumnType::URL => 'abc',
        ], [
            ColumnType::URL => [$this->getErrorMessage('url', ColumnType::URL)],
        ]);
    }


    // Email ----------------------------------------------------

    /**
     * @return void
     */
    public function testSuccessEmail()
    {
        $this->executeTestAllColumns(ColumnType::EMAIL, [
            ColumnType::EMAIL => "info@exment.net",
        ]);
    }

    /**
     * @return void
     */
    public function testEmailNotString()
    {
        $this->executeTestAllColumns(ColumnType::EMAIL, [
            ColumnType::EMAIL => ['info@exment.net'],
        ], [
            ColumnType::EMAIL => [$this->getErrorMessage('email', ColumnType::EMAIL)],
        ]);
    }

    /**
     * @return void
     */
    public function testEmailNotString2()
    {
        $this->executeTestAllColumns(ColumnType::EMAIL, [
            ColumnType::EMAIL => 1,
        ], [
            ColumnType::EMAIL => [$this->getErrorMessage('email', ColumnType::EMAIL)],
        ]);
    }

    /**
     * @return void
     */
    public function testEmailNotEmail()
    {
        $this->executeTestAllColumns(ColumnType::EMAIL, [
            ColumnType::EMAIL => 'abc',
        ], [
            ColumnType::EMAIL => [$this->getErrorMessage('email', ColumnType::EMAIL)],
        ]);
    }


    // Integer ----------------------------------------------------

    /**
     * @return void
     */
    public function testSuccessInteger()
    {
        $this->executeTestAllColumns(ColumnType::INTEGER, [
            ColumnType::INTEGER => 1,
        ]);
    }

    /**
     * @return void
     */
    public function testSuccessInteger2()
    {
        $this->executeTestAllColumns(ColumnType::INTEGER, [
            ColumnType::INTEGER => "1",
        ]);
    }

    /**
     * @return void
     */
    public function testSuccessInteger3()
    {
        $this->executeTestAllColumns(ColumnType::INTEGER, [
            ColumnType::INTEGER => "1,000",
        ]);
    }

    /**
     * @return void
     */
    public function testIntegerNot()
    {
        $this->executeTestAllColumns(ColumnType::INTEGER, [
            ColumnType::INTEGER => [1],
        ], [
            ColumnType::INTEGER => [$this->getErrorMessage('integer', ColumnType::INTEGER)],
        ]);
    }

    /**
     * @return void
     */
    public function testIntegerNot2()
    {
        $this->executeTestAllColumns(ColumnType::INTEGER, [
            ColumnType::INTEGER => 'abc',
        ], [
            ColumnType::INTEGER => [$this->getErrorMessage('integer', ColumnType::INTEGER)],
        ]);
    }




    // DECIMAL ----------------------------------------------------

    /**
     * @return void
     */
    public function testSuccessDecimal()
    {
        $this->executeTestAllColumns(ColumnType::DECIMAL, [
            ColumnType::DECIMAL => 1,
        ]);
    }

    /**
     * @return void
     */
    public function testSuccessDecimal2()
    {
        $this->executeTestAllColumns(ColumnType::DECIMAL, [
            ColumnType::DECIMAL => "1",
        ]);
    }

    /**
     * @return void
     */
    public function testSuccessDecimal3()
    {
        $this->executeTestAllColumns(ColumnType::DECIMAL, [
            ColumnType::DECIMAL => "1,000",
        ]);
    }

    /**
     * @return void
     */
    public function testSuccessDecimal4()
    {
        $this->executeTestAllColumns(ColumnType::DECIMAL, [
            ColumnType::DECIMAL => 1000.02,
        ]);
    }

    /**
     * @return void
     */
    public function testSuccessDecimal5()
    {
        $this->executeTestAllColumns(ColumnType::DECIMAL, [
            ColumnType::DECIMAL => "1000.02",
        ]);
    }

    /**
     * @return void
     */
    public function testSuccessDecimal6()
    {
        $this->executeTestAllColumns(ColumnType::DECIMAL, [
            ColumnType::DECIMAL => "1,000.02",
        ]);
    }

    /**
     * @return void
     */
    public function testDecimalNot()
    {
        $this->executeTestAllColumns(ColumnType::DECIMAL, [
            ColumnType::DECIMAL => [1],
        ], [
            ColumnType::DECIMAL => [$this->getErrorMessage('numeric', ColumnType::DECIMAL)],
        ]);
    }

    /**
     * @return void
     */
    public function testDecimalNot2()
    {
        $this->executeTestAllColumns(ColumnType::DECIMAL, [
            ColumnType::DECIMAL => 'abc',
        ], [
            ColumnType::DECIMAL => [$this->getErrorMessage('numeric', ColumnType::DECIMAL)],
        ]);
    }



    // CURRENCY ----------------------------------------------------

    /**
     * @return void
     */
    public function testSuccessCurrency()
    {
        $this->executeTestAllColumns(ColumnType::CURRENCY, [
            ColumnType::CURRENCY => 1,
        ]);
    }

    /**
     * @return void
     */
    public function testSuccessCurrency2()
    {
        $this->executeTestAllColumns(ColumnType::CURRENCY, [
            ColumnType::CURRENCY => "1",
        ]);
    }

    /**
     * @return void
     */
    public function testSuccessCurrency3()
    {
        $this->executeTestAllColumns(ColumnType::CURRENCY, [
            ColumnType::CURRENCY => "1,000",
        ]);
    }

    /**
     * @return void
     */
    public function testSuccessCurrency4()
    {
        $this->executeTestAllColumns(ColumnType::CURRENCY, [
            ColumnType::CURRENCY => 1000.02,
        ]);
    }

    /**
     * @return void
     */
    public function testSuccessCurrency5()
    {
        $this->executeTestAllColumns(ColumnType::CURRENCY, [
            ColumnType::CURRENCY => "1000.02",
        ]);
    }

    /**
     * @return void
     */
    public function testSuccessCurrency6()
    {
        $this->executeTestAllColumns(ColumnType::CURRENCY, [
            ColumnType::CURRENCY => "1,000.02",
        ]);
    }


    /**
     * @return void
     */
    public function testCurrencyNot()
    {
        $this->executeTestAllColumns(ColumnType::CURRENCY, [
            ColumnType::CURRENCY => [1],
        ], [
            ColumnType::CURRENCY => [$this->getErrorMessage('numeric', ColumnType::CURRENCY)],
        ]);
    }

    /**
     * @return void
     */
    public function testCurrencyNot2()
    {
        $this->executeTestAllColumns(ColumnType::CURRENCY, [
            ColumnType::CURRENCY => 'abc',
        ], [
            ColumnType::CURRENCY => [$this->getErrorMessage('numeric', ColumnType::CURRENCY)],
        ]);
    }



    // DATE ----------------------------------------------------

    /**
     * @return void
     */
    public function testSuccessDate()
    {
        $this->executeTestAllColumns(ColumnType::DATE, [
            ColumnType::DATE => '2020-07-01',
        ]);
    }

    /**
     * @return void
     */
    public function testSuccessDate2()
    {
        $this->executeTestAllColumns(ColumnType::DATE, [
            ColumnType::DATE => '2020-07-01 03:00:00',
        ]);
    }

    /**
     * @return void
     */
    public function testSuccessDate3()
    {
        $this->executeTestAllColumns(ColumnType::DATE, [
            ColumnType::DATE => new \Carbon\Carbon('2020-07-01'),
        ], [
        ], [
            ColumnType::DATE => \Exment::carbonToArray(new \Carbon\Carbon('2020-07-01')),
        ]);
    }

    /**
     * @return void
     */
    public function testSuccessDate4()
    {
        $this->executeTestAllColumns(ColumnType::DATE, [
            ColumnType::DATE => new \Carbon\Carbon('2020-07-01 03:00:00'),
        ], [
        ], [
            ColumnType::DATE => \Exment::carbonToArray(new \Carbon\Carbon('2020-07-01 03:00:00')),
        ]);
    }

    /**
     * @return void
     */
    public function testDateNot()
    {
        $this->executeTestAllColumns(ColumnType::DATE, [
            ColumnType::DATE => [1],
        ], [
            ColumnType::DATE => [$this->getErrorMessage('date', ColumnType::DATE)],
        ]);
    }

    /**
     * @return void
     */
    public function testDateNot2()
    {
        $this->executeTestAllColumns(ColumnType::DATE, [
            ColumnType::DATE => 'abc',
        ], [
            ColumnType::DATE => [$this->getErrorMessage('date', ColumnType::DATE)],
        ]);
    }

    /**
     * @return void
     */
    public function testDateNot3()
    {
        $this->executeTestAllColumns(ColumnType::DATE, [
            ColumnType::DATE => '123',
        ], [
            ColumnType::DATE => [$this->getErrorMessage('date', ColumnType::DATE)],
        ]);
    }

    /**
     * @return void
     */
    public function testDateNot4()
    {
        $this->executeTestAllColumns(ColumnType::DATE, [
            ColumnType::DATE => 123,
        ], [
            ColumnType::DATE => [$this->getErrorMessage('date', ColumnType::DATE)],
        ]);
    }




    // TIME ----------------------------------------------------

    /**
     * @return void
     */
    public function testSuccessTime()
    {
        $this->executeTestAllColumns(ColumnType::TIME, [
            ColumnType::TIME => '03:00:00',
        ]);
    }

    /**
     * @return void
     */
    public function testSuccessTime2()
    {
        $this->executeTestAllColumns(ColumnType::TIME, [
            ColumnType::TIME => '1234',
        ]);
    }

    /**
     * @return void
     */
    public function testSuccessTime3()
    {
        $this->executeTestAllColumns(ColumnType::TIME, [
            ColumnType::TIME => '12:34',
        ]);
    }

    /**
     * @return void
     */
    public function testSuccessTime4()
    {
        $this->executeTestAllColumns(ColumnType::TIME, [
            ColumnType::TIME => '123456',
        ]);
    }

    /**
     * @return void
     */
    public function testTimeNot()
    {
        $this->executeTestAllColumns(ColumnType::TIME, [
            ColumnType::TIME => [1],
        ], [
            ColumnType::TIME => [$this->getErrorMessage('regex', ColumnType::TIME)],
        ]);
    }

    /**
     * @return void
     */
    public function testTimeNot2()
    {
        $this->executeTestAllColumns(ColumnType::TIME, [
            ColumnType::TIME => 'abc',
        ], [
            ColumnType::TIME => [$this->getErrorMessage('regex', ColumnType::TIME)],
        ]);
    }

    /**
     * @return void
     */
    public function testTimeNot3()
    {
        $this->executeTestAllColumns(ColumnType::TIME, [
            ColumnType::TIME => '123',
        ], [
            ColumnType::TIME => [$this->getErrorMessage('regex', ColumnType::TIME)],
        ]);
    }

    /**
     * @return void
     */
    public function testTimeNot4()
    {
        $this->executeTestAllColumns(ColumnType::TIME, [
            ColumnType::TIME => 123,
        ], [
            ColumnType::TIME => [$this->getErrorMessage('regex', ColumnType::TIME)],
        ]);
    }

    /**
     * @return void
     */
    public function testTimeNot5()
    {
        $this->executeTestAllColumns(ColumnType::TIME, [
            ColumnType::TIME => '45:56',
        ], [
            ColumnType::TIME => [$this->getErrorMessage('regex', ColumnType::TIME)],
        ]);
    }




    // DATETIME ----------------------------------------------------

    /**
     * @return void
     */
    public function testSuccessDatetime()
    {
        $this->executeTestAllColumns(ColumnType::DATETIME, [
            ColumnType::DATETIME => '2020-07-01',
        ]);
    }

    /**
     * @return void
     */
    public function testSuccessDatetime2()
    {
        $this->executeTestAllColumns(ColumnType::DATETIME, [
            ColumnType::DATETIME => '2020-07-01 03:00:00',
        ]);
    }

    /**
     * @return void
     */
    public function testSuccessDatetime3()
    {
        $this->executeTestAllColumns(ColumnType::DATETIME, [
            ColumnType::DATETIME => new \Carbon\Carbon('2020-07-01'),
        ], [
        ], [
            ColumnType::DATETIME => \Exment::carbonToArray(new \Carbon\Carbon('2020-07-01')),
        ]);
    }

    /**
     * @return void
     */
    public function testSuccessDatetime4()
    {
        $this->executeTestAllColumns(ColumnType::DATETIME, [
            ColumnType::DATETIME => new \Carbon\Carbon('2020-07-01 03:00:00'),
        ], [
        ], [
            ColumnType::DATETIME => \Exment::carbonToArray(new \Carbon\Carbon('2020-07-01 03:00:00')),
        ]);
    }

    /**
     * @return void
     */
    public function testDatetimeNot()
    {
        $this->executeTestAllColumns(ColumnType::DATETIME, [
            ColumnType::DATETIME => [1],
        ], [
            ColumnType::DATETIME => [$this->getErrorMessage('date', ColumnType::DATETIME)],
        ]);
    }

    /**
     * @return void
     */
    public function testDatetimeNot2()
    {
        $this->executeTestAllColumns(ColumnType::DATETIME, [
            ColumnType::DATETIME => 'abc',
        ], [
            ColumnType::DATETIME => [$this->getErrorMessage('date', ColumnType::DATETIME)],
        ]);
    }
    /**
     * @return void
     */
    public function testDatetimeNot3()
    {
        $this->executeTestAllColumns(ColumnType::DATETIME, [
            ColumnType::DATETIME => '123',
        ], [
            ColumnType::DATETIME => [$this->getErrorMessage('date', ColumnType::DATETIME)],
        ]);
    }
    /**
     * @return void
     */
    public function testDatetimeNot4()
    {
        $this->executeTestAllColumns(ColumnType::DATETIME, [
            ColumnType::DATETIME => 123,
        ], [
            ColumnType::DATETIME => [$this->getErrorMessage('date', ColumnType::DATETIME)],
        ]);
    }



    // SELECT ----------------------------------------------------
    /**
     * @return void
     */
    public function testSuccessSelect()
    {
        $this->executeTestAllColumns(ColumnType::SELECT, [
            ColumnType::SELECT => 'foo',
        ]);
    }
    /**
     * @return void
     */
    public function testSuccessSelect2()
    {
        $this->executeTestAllColumns(ColumnType::SELECT, [
            ColumnType::SELECT => 'foo,bar',
        ]);
    }
    /**
     * @return void
     */
    public function testSuccessSelect3()
    {
        $this->executeTestAllColumns(ColumnType::SELECT, [
            ColumnType::SELECT => ['foo','bar'],
        ]);
    }
    /**
     * @return void
     */
    public function testSelectNot()
    {
        $this->executeTestAllColumns(ColumnType::SELECT, [
            ColumnType::SELECT => 'abcabc',
        ], [
            ColumnType::SELECT => [$this->getErrorMessage('in', ColumnType::SELECT, [
                'values' => 'foo、bar、baz',
            ])],
        ]);
    }
    /**
     * @return void
     */
    public function testSelectNot2()
    {
        $this->executeTestAllColumns(ColumnType::SELECT, [
            ColumnType::SELECT => 'foo,abcabc',
        ], [
            ColumnType::SELECT => [$this->getErrorMessage('in', ColumnType::SELECT, [
                'values' => 'foo、bar、baz',
            ])],
        ]);
    }
    /**
     * @return void
     */
    public function testSelectNot3()
    {
        $this->executeTestAllColumns(ColumnType::SELECT, [
            ColumnType::SELECT => ['foo','abcabc'],
        ], [
            ColumnType::SELECT => [$this->getErrorMessage('in', ColumnType::SELECT, [
                'values' => 'foo、bar、baz',
            ])],
        ]);
    }




    // SELECT_VALTEXT ----------------------------------------------------
    /**
     * @return void
     */
    public function testSuccessSelectValText()
    {
        $this->executeTestAllColumns(ColumnType::SELECT_VALTEXT, [
            ColumnType::SELECT_VALTEXT => 'foo',
        ]);
    }
    /**
     * @return void
     */
    public function testSuccessSelectValText2()
    {
        $this->executeTestAllColumns(ColumnType::SELECT_VALTEXT, [
            ColumnType::SELECT_VALTEXT => 'FOO',
        ]);
    }
    /**
     * @return void
     */
    public function testSuccessSelectValText3()
    {
        $this->executeTestAllColumns(ColumnType::SELECT_VALTEXT, [
            ColumnType::SELECT_VALTEXT => 'foo,bar',
        ]);
    }
    /**
     * @return void
     */
    public function testSuccessSelectValText4()
    {
        $this->executeTestAllColumns(ColumnType::SELECT_VALTEXT, [
            ColumnType::SELECT_VALTEXT => 'FOO,bar',
        ]);
    }
    /**
     * @return void
     */
    public function testSuccessSelectValText5()
    {
        $this->executeTestAllColumns(ColumnType::SELECT_VALTEXT, [
            ColumnType::SELECT_VALTEXT => ['foo', 'bar'],
        ]);
    }
    /**
     * @return void
     */
    public function testSuccessSelectValText6()
    {
        $this->executeTestAllColumns(ColumnType::SELECT_VALTEXT, [
            ColumnType::SELECT_VALTEXT => ['FOO', 'bar'],
        ]);
    }

    /**
     * @return void
     */
    public function testSelectValTextNot()
    {
        $this->executeTestAllColumns(ColumnType::SELECT_VALTEXT, [
            ColumnType::SELECT_VALTEXT => 'abcabc',
        ], [
            ColumnType::SELECT_VALTEXT => [$this->getErrorMessage('in', ColumnType::SELECT_VALTEXT, [
                'values' => 'FOO、BAR、BAZ',
            ])],
        ]);
    }
    /**
     * @return void
     */
    public function testSelectValTextNot2()
    {
        $this->executeTestAllColumns(ColumnType::SELECT_VALTEXT, [
            ColumnType::SELECT_VALTEXT => 'abcabc,foo',
        ], [
            ColumnType::SELECT_VALTEXT => [$this->getErrorMessage('in', ColumnType::SELECT_VALTEXT, [
                'values' => 'FOO、BAR、BAZ',
            ])],
        ]);
    }
    /**
     * @return void
     */
    public function testSelectValTextNot3()
    {
        $this->executeTestAllColumns(ColumnType::SELECT_VALTEXT, [
            ColumnType::SELECT_VALTEXT => 'abcabc,FOO',
        ], [
            ColumnType::SELECT_VALTEXT => [$this->getErrorMessage('in', ColumnType::SELECT_VALTEXT, [
                'values' => 'FOO、BAR、BAZ',
            ])],
        ]);
    }
    /**
     * @return void
     */
    public function testSelectValTextNot4()
    {
        $this->executeTestAllColumns(ColumnType::SELECT_VALTEXT, [
            ColumnType::SELECT_VALTEXT => ['abcabc','foo'],
        ], [
            ColumnType::SELECT_VALTEXT => [$this->getErrorMessage('in', ColumnType::SELECT_VALTEXT, [
                'values' => 'FOO、BAR、BAZ',
            ])],
        ]);
    }
    /**
     * @return void
     */
    public function testSelectValTextNot5()
    {
        $this->executeTestAllColumns(ColumnType::SELECT_VALTEXT, [
            ColumnType::SELECT_VALTEXT => ['abcabc', 'FOO'],
        ], [
            ColumnType::SELECT_VALTEXT => [$this->getErrorMessage('in', ColumnType::SELECT_VALTEXT, [
                'values' => 'FOO、BAR、BAZ',
            ])],
        ]);
    }



    // SELECT_TABLE ----------------------------------------------------
    /**
     * @return void
     */
    public function testSuccessSelectTable()
    {
        $this->executeTestAllColumns(ColumnType::SELECT_TABLE, [
            ColumnType::SELECT_TABLE => '1',
        ]);
    }
    /**
     * @return void
     */
    public function testSuccessSelectTable2()
    {
        $this->executeTestAllColumns(ColumnType::SELECT_TABLE, [
            ColumnType::SELECT_TABLE => '1,2',
        ]);
    }
    /**
     * @return void
     */
    public function testSuccessSelectTable3()
    {
        $this->executeTestAllColumns(ColumnType::SELECT_TABLE, [
            ColumnType::SELECT_TABLE => ['1', '2'],
        ]);
    }

    /**
     * @return void
     */
    public function testSelectTableNot()
    {
        $this->executeTestAllColumns(ColumnType::SELECT_TABLE, [
            ColumnType::SELECT_TABLE => 'abcabc',
        ], [
            ColumnType::SELECT_TABLE => [
                $this->getErrorMessage('numeric', ColumnType::SELECT_TABLE),
                exmtrans('validation.not_has_custom_value', [
                    'table_view_name' => CustomTable::getEloquent('custom_value_view_all')->table_view_name,
                    'attribute' => ColumnType::SELECT_TABLE,
                    'value' => null,
                ]),
            ],
        ]);
    }
    /**
     * @return void
     */
    public function testSelectTableNot2()
    {
        $this->executeTestAllColumns(ColumnType::SELECT_TABLE, [
            ColumnType::SELECT_TABLE => '9999999',
        ], [
            ColumnType::SELECT_TABLE => [
                exmtrans('validation.not_has_custom_value', [
                    'table_view_name' => CustomTable::getEloquent('custom_value_view_all')->table_view_name,
                    'attribute' => ColumnType::SELECT_TABLE,
                    'value' => null,
                ]),
            ],
        ]);
    }
    /**
     * @return void
     */
    public function testSelectTableNot3()
    {
        $this->executeTestAllColumns(ColumnType::SELECT_TABLE, [
            ColumnType::SELECT_TABLE => ['abcabc'],
        ], [
            ColumnType::SELECT_TABLE => [
                $this->getErrorMessage('numeric', ColumnType::SELECT_TABLE),
                exmtrans('validation.not_has_custom_value', [
                    'table_view_name' => CustomTable::getEloquent('custom_value_view_all')->table_view_name,
                    'attribute' => ColumnType::SELECT_TABLE,
                    'value' => null,
                ]),
            ],
        ]);
    }
    /**
     * @return void
     */
    public function testSelectTableNot4()
    {
        $this->executeTestAllColumns(ColumnType::SELECT_TABLE, [
            ColumnType::SELECT_TABLE => ['9999999', '123'],
        ], [
            ColumnType::SELECT_TABLE => [
                exmtrans('validation.not_has_custom_value', [
                    'table_view_name' => CustomTable::getEloquent('custom_value_view_all')->table_view_name,
                    'attribute' => ColumnType::SELECT_TABLE,
                    'value' => null,
                ]),
            ],
        ]);
    }

    /**
     * Test for custom table edit. Not has permission
     *
     * @return void
     */
    public function testSelectTableNot5()
    {
        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->executeTestAllColumns(ColumnType::SELECT_TABLE, [
            'select_table_2' => ['1', '2'],
        ], [
            'select_table_2' => [
                exmtrans('validation.not_has_custom_value', [
                    'table_view_name' => CustomTable::getEloquent('custom_value_edit')->table_view_name,
                    'attribute' => 'select_table_2',
                    'value' => null,
                ]),
            ],
        ]);
    }


    // YESNO ----------------------------------------------------
    /**
     * @return void
     */
    public function testSuccessYesNo()
    {
        $this->executeTestAllColumns(ColumnType::YESNO, [
            ColumnType::YESNO => 1,
        ]);
    }
    /**
     * @return void
     */
    public function testSuccessYesNo2()
    {
        $this->executeTestAllColumns(ColumnType::YESNO, [
            ColumnType::YESNO => '1',
        ]);
    }
    /**
     * @return void
     */
    public function testSuccessYesNo3()
    {
        $this->executeTestAllColumns(ColumnType::YESNO, [
            ColumnType::YESNO => 0,
        ]);
    }
    /**
     * @return void
     */
    public function testSuccessYesNo4()
    {
        $this->executeTestAllColumns(ColumnType::YESNO, [
            ColumnType::YESNO => '0',
        ]);
    }
    /**
     * @return void
     */
    public function testSuccessYesNo5()
    {
        $this->executeTestAllColumns(ColumnType::YESNO, [
            ColumnType::YESNO => 'YES',
        ]);
    }
    /**
     * @return void
     */
    public function testSuccessYesNo6()
    {
        $this->executeTestAllColumns(ColumnType::YESNO, [
            ColumnType::YESNO => 'yes',
        ]);
    }
    /**
     * @return void
     */
    public function testSuccessYesNo7()
    {
        $this->executeTestAllColumns(ColumnType::YESNO, [
            ColumnType::YESNO => 'NO',
        ]);
    }
    /**
     * @return void
     */
    public function testSuccessYesNo8()
    {
        $this->executeTestAllColumns(ColumnType::YESNO, [
            ColumnType::YESNO => 'no',
        ]);
    }
    /**
     * @return void
     */
    public function testSuccessYesNo9()
    {
        $this->executeTestAllColumns(ColumnType::YESNO, [
            ColumnType::YESNO => true,
        ]);
    }
    /**
     * @return void
     */
    public function testSuccessYesNo10()
    {
        $this->executeTestAllColumns(ColumnType::YESNO, [
            ColumnType::YESNO => false,
        ]);
    }
    /**
     * @return void
     */
    public function testSuccessYesNo11()
    {
        $this->executeTestAllColumns(ColumnType::YESNO, [
            ColumnType::YESNO => 'true',
        ]);
    }
    /**
     * @return void
     */
    public function testSuccessYesNo12()
    {
        $this->executeTestAllColumns(ColumnType::YESNO, [
            ColumnType::YESNO => 'false',
        ]);
    }

    /**
     * @return void
     */
    public function testYesNoNot()
    {
        $this->executeTestAllColumns(ColumnType::YESNO, [
            ColumnType::YESNO => 'abcabc',
        ], [
            ColumnType::YESNO => [$this->getErrorMessage('in', ColumnType::YESNO, [
                'values' => '0,1,YES,NO,yes,no,true,false',
            ])],
        ]);
    }
    /**
     * @return void
     */
    public function testYesNoNot2()
    {
        $this->executeTestAllColumns(ColumnType::YESNO, [
            ColumnType::YESNO => '2',
        ], [
            ColumnType::YESNO => [$this->getErrorMessage('in', ColumnType::YESNO, [
                'values' => '0,1,YES,NO,yes,no,true,false',
            ])],
        ]);
    }



    // BOOLEAN ----------------------------------------------------
    /**
     * @return void
     */
    public function testSuccessBoolean()
    {
        $this->executeTestAllColumns(ColumnType::BOOLEAN, [
            ColumnType::BOOLEAN => 'ok',
        ]);
    }
    /**
     * @return void
     */
    public function testSuccessBoolean2()
    {
        $this->executeTestAllColumns(ColumnType::BOOLEAN, [
            ColumnType::BOOLEAN => 'OK',
        ]);
    }
    /**
     * @return void
     */
    public function testSuccessBoolean3()
    {
        $this->executeTestAllColumns(ColumnType::BOOLEAN, [
            ColumnType::BOOLEAN => 'ng',
        ]);
    }
    /**
     * @return void
     */
    public function testSuccessBoolean4()
    {
        $this->executeTestAllColumns(ColumnType::BOOLEAN, [
            ColumnType::BOOLEAN => 'NG',
        ]);
    }
    /**
     * @return void
     */
    public function testBooleanNot()
    {
        $this->executeTestAllColumns(ColumnType::BOOLEAN, [
            ColumnType::BOOLEAN => 'abcabc',
        ], [
            ColumnType::BOOLEAN => [$this->getErrorMessage('in', ColumnType::BOOLEAN, [
                'values' => 'ng,NG,ok,OK',
            ])],
        ]);
    }
    /**
     * @return void
     */
    public function testBooleanNot2()
    {
        $this->executeTestAllColumns(ColumnType::BOOLEAN, [
            ColumnType::BOOLEAN => 'no',
        ], [
            ColumnType::BOOLEAN => [$this->getErrorMessage('in', ColumnType::BOOLEAN, [
                'values' => 'ng,NG,ok,OK',
            ])],
        ]);
    }


    /**
     * @param array<mixed> $values
     * @param array<mixed> $errors
     * @param array<mixed> $matches
     * @param mixed|null $id
     * @return void
     */
    protected function executeTestEdit(array $values, array $errors = [], array $matches = [], $id = null)
    {
        $custom_value = CustomTable::getEloquent('custom_value_edit')->getValueModel($id);

        $this->executeTest($custom_value, $values, $errors, $matches);
    }

    /**
     * @param string $column_type
     * @param array<mixed> $values
     * @param array<mixed> $errors
     * @param array<mixed> $matches
     * @return void
     */
    protected function executeTestAllColumns($column_type, array $values, array $errors = [], array $matches = [])
    {
        $custom_column = $this->getCustomColumnModel($column_type);
        $custom_value = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST)->getValueModel();

        $this->executeTest($custom_value, $values, $errors, $matches);
    }

    /**
     * @param mixed $custom_value
     * @param array<mixed> $values
     * @param array<mixed> $errors
     * @param array<mixed> $matches
     * @return void
     */
    protected function executeTest($custom_value, array $values, array $errors = [], array $matches = [])
    {
        $isSuccess = count($errors) == 0;
        try {
            $custom_value->setValueStrictly($values);

            if (!$isSuccess) {
                $this->assertTrue(false, 'This test excepts exception, but success validation.');
                return;
            }

            if (count($values) == 0) {
                $this->assertTrue(true);
                return;
            }
            foreach ($values as $key => $value) {
                $checkValue = count($matches) == 0 ? $value : array_get($matches, $key);
                $this->assertMatch($checkValue, array_get($custom_value->value, $key));
            }
        } catch (ValidationException $ex) {
            if ($isSuccess) {
                /** @phpstan-ignore-next-line  */
                $this->assertTrue(false, 'This test excepts success, but falls validation. Messages : ' . json_encode($ex->validator->getMessages()));
            }

            //TODO: Check error messages
            /** @phpstan-ignore-next-line  */
            $messages = $ex->validator->getMessages();
            $this->assertJsonExment($errors, $messages);
        }
    }

    /**
     * @param mixed $validatekey
     * @param mixed $column
     * @param array<mixed> $messages
     * @return mixed
     */
    protected function getErrorMessage($validatekey, $column, array $messages = [])
    {
        $array = array_merge($messages, ['attribute' => $column]);
        return trans("validation.$validatekey", $array);
    }

    /**
     * @param null|string $id
     * @return void
     */
    protected function login($id = null)
    {
        $this->be(LoginUser::find($id ?? 1));
    }
}
