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
    public function testSuccess()
    {
        $this->executeTestEdit([
            'text' => 'abc',
        ], [
        ]);
    }

    public function testRequired()
    {
        $this->executeTestEdit([
        ], [
            'text' => [$this->getErrorMessage('required', 'text')],
        ]);
    }

    /**
     * Update check,. It's Success
     */
    public function testRequiredUpdate()
    {
        $this->executeTestEdit([
        ], [], [], 1);
    }

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
    public function testSuccessText()
    {
        $this->executeTestAllColumns(ColumnType::TEXT, [
            ColumnType::TEXT => 'abc',
        ]);
    }
    public function testSuccessText2()
    {
        $this->executeTestAllColumns(ColumnType::TEXT, [
            ColumnType::TEXT => 1,
        ]);
    }
    public function testTextNotString()
    {
        $this->executeTestAllColumns(ColumnType::TEXT, [
            ColumnType::TEXT => ['abc'],
        ], [
            ColumnType::TEXT => [$this->getErrorMessage('string', ColumnType::TEXT)],
        ]);
    }



    // Textarea ----------------------------------------------------
    public function testSuccessTextarea()
    {
        $this->executeTestAllColumns(ColumnType::TEXTAREA, [
            ColumnType::TEXTAREA => "abc\r\ndef",
        ]);
    }
    public function testSuccessTextarea2()
    {
        $this->executeTestAllColumns(ColumnType::TEXTAREA, [
            ColumnType::TEXTAREA => 1,
        ]);
    }


    // Textarea ----------------------------------------------------
    public function testSuccessEditor()
    {
        $this->executeTestAllColumns(ColumnType::EDITOR, [
            ColumnType::EDITOR => "abc\r\ndef",
        ]);
    }
    public function testSuccessEditor2()
    {
        $this->executeTestAllColumns(ColumnType::EDITOR, [
            ColumnType::EDITOR => 1,
        ]);
    }


    // Url ----------------------------------------------------
    public function testSuccessUrl()
    {
        $this->executeTestAllColumns(ColumnType::URL, [
            ColumnType::URL => "https://github.com/exceedone/exment",
        ]);
    }

    public function testUrlNotString()
    {
        $this->executeTestAllColumns(ColumnType::URL, [
            ColumnType::URL => ['https://github.com/exceedone/exment'],
        ], [
            ColumnType::URL => [$this->getErrorMessage('url', ColumnType::URL)],
        ]);
    }
    public function testUrlNotString2()
    {
        $this->executeTestAllColumns(ColumnType::URL, [
            ColumnType::URL => 1,
        ], [
            ColumnType::URL => [$this->getErrorMessage('url', ColumnType::URL)],
        ]);
    }

    public function testUrlNotUrl()
    {
        $this->executeTestAllColumns(ColumnType::URL, [
            ColumnType::URL => 'abc',
        ], [
            ColumnType::URL => [$this->getErrorMessage('url', ColumnType::URL)],
        ]);
    }


    // Email ----------------------------------------------------
    public function testSuccessEmail()
    {
        $this->executeTestAllColumns(ColumnType::EMAIL, [
            ColumnType::EMAIL => "info@exment.net",
        ]);
    }

    public function testEmailNotString()
    {
        $this->executeTestAllColumns(ColumnType::EMAIL, [
            ColumnType::EMAIL => ['info@exment.net'],
        ], [
            ColumnType::EMAIL => [$this->getErrorMessage('email', ColumnType::EMAIL)],
        ]);
    }
    public function testEmailNotString2()
    {
        $this->executeTestAllColumns(ColumnType::EMAIL, [
            ColumnType::EMAIL => 1,
        ], [
            ColumnType::EMAIL => [$this->getErrorMessage('email', ColumnType::EMAIL)],
        ]);
    }

    public function testEmailNotEmail()
    {
        $this->executeTestAllColumns(ColumnType::EMAIL, [
            ColumnType::EMAIL => 'abc',
        ], [
            ColumnType::EMAIL => [$this->getErrorMessage('email', ColumnType::EMAIL)],
        ]);
    }


    // Integer ----------------------------------------------------
    public function testSuccessInteger()
    {
        $this->executeTestAllColumns(ColumnType::INTEGER, [
            ColumnType::INTEGER => 1,
        ]);
    }

    public function testSuccessInteger2()
    {
        $this->executeTestAllColumns(ColumnType::INTEGER, [
            ColumnType::INTEGER => "1",
        ]);
    }

    public function testSuccessInteger3()
    {
        $this->executeTestAllColumns(ColumnType::INTEGER, [
            ColumnType::INTEGER => "1,000",
        ]);
    }

    public function testIntegerNot()
    {
        $this->executeTestAllColumns(ColumnType::INTEGER, [
            ColumnType::INTEGER => [1],
        ], [
            ColumnType::INTEGER => [$this->getErrorMessage('integer', ColumnType::INTEGER)],
        ]);
    }
    public function testIntegerNot2()
    {
        $this->executeTestAllColumns(ColumnType::INTEGER, [
            ColumnType::INTEGER => 'abc',
        ], [
            ColumnType::INTEGER => [$this->getErrorMessage('integer', ColumnType::INTEGER)],
        ]);
    }




    // DECIMAL ----------------------------------------------------
    public function testSuccessDecimal()
    {
        $this->executeTestAllColumns(ColumnType::DECIMAL, [
            ColumnType::DECIMAL => 1,
        ]);
    }

    public function testSuccessDecimal2()
    {
        $this->executeTestAllColumns(ColumnType::DECIMAL, [
            ColumnType::DECIMAL => "1",
        ]);
    }

    public function testSuccessDecimal3()
    {
        $this->executeTestAllColumns(ColumnType::DECIMAL, [
            ColumnType::DECIMAL => "1,000",
        ]);
    }

    public function testSuccessDecimal4()
    {
        $this->executeTestAllColumns(ColumnType::DECIMAL, [
            ColumnType::DECIMAL => 1000.02,
        ]);
    }

    public function testSuccessDecimal5()
    {
        $this->executeTestAllColumns(ColumnType::DECIMAL, [
            ColumnType::DECIMAL => "1000.02",
        ]);
    }

    public function testSuccessDecimal6()
    {
        $this->executeTestAllColumns(ColumnType::DECIMAL, [
            ColumnType::DECIMAL => "1,000.02",
        ]);
    }

    public function testDecimalNot()
    {
        $this->executeTestAllColumns(ColumnType::DECIMAL, [
            ColumnType::DECIMAL => [1],
        ], [
            ColumnType::DECIMAL => [$this->getErrorMessage('numeric', ColumnType::DECIMAL)],
        ]);
    }
    public function testDecimalNot2()
    {
        $this->executeTestAllColumns(ColumnType::DECIMAL, [
            ColumnType::DECIMAL => 'abc',
        ], [
            ColumnType::DECIMAL => [$this->getErrorMessage('numeric', ColumnType::DECIMAL)],
        ]);
    }



    // CURRENCY ----------------------------------------------------
    public function testSuccessCurrency()
    {
        $this->executeTestAllColumns(ColumnType::CURRENCY, [
            ColumnType::CURRENCY => 1,
        ]);
    }

    public function testSuccessCurrency2()
    {
        $this->executeTestAllColumns(ColumnType::CURRENCY, [
            ColumnType::CURRENCY => "1",
        ]);
    }

    public function testSuccessCurrency3()
    {
        $this->executeTestAllColumns(ColumnType::CURRENCY, [
            ColumnType::CURRENCY => "1,000",
        ]);
    }

    public function testSuccessCurrency4()
    {
        $this->executeTestAllColumns(ColumnType::CURRENCY, [
            ColumnType::CURRENCY => 1000.02,
        ]);
    }

    public function testSuccessCurrency5()
    {
        $this->executeTestAllColumns(ColumnType::CURRENCY, [
            ColumnType::CURRENCY => "1000.02",
        ]);
    }

    public function testSuccessCurrency6()
    {
        $this->executeTestAllColumns(ColumnType::CURRENCY, [
            ColumnType::CURRENCY => "1,000.02",
        ]);
    }


    public function testCurrencyNot()
    {
        $this->executeTestAllColumns(ColumnType::CURRENCY, [
            ColumnType::CURRENCY => [1],
        ], [
            ColumnType::CURRENCY => [$this->getErrorMessage('numeric', ColumnType::CURRENCY)],
        ]);
    }
    public function testCurrencyNot2()
    {
        $this->executeTestAllColumns(ColumnType::CURRENCY, [
            ColumnType::CURRENCY => 'abc',
        ], [
            ColumnType::CURRENCY => [$this->getErrorMessage('numeric', ColumnType::CURRENCY)],
        ]);
    }



    // DATE ----------------------------------------------------
    public function testSuccessDate()
    {
        $this->executeTestAllColumns(ColumnType::DATE, [
            ColumnType::DATE => '2020-07-01',
        ]);
    }

    public function testSuccessDate2()
    {
        $this->executeTestAllColumns(ColumnType::DATE, [
            ColumnType::DATE => '2020-07-01 03:00:00',
        ]);
    }

    public function testSuccessDate3()
    {
        $this->executeTestAllColumns(ColumnType::DATE, [
            ColumnType::DATE => new \Carbon\Carbon('2020-07-01'),
        ], [
        ], [
            ColumnType::DATE => \Exment::carbonToArray(new \Carbon\Carbon('2020-07-01')),
        ]);
    }

    public function testSuccessDate4()
    {
        $this->executeTestAllColumns(ColumnType::DATE, [
            ColumnType::DATE => new \Carbon\Carbon('2020-07-01 03:00:00'),
        ], [
        ], [
            ColumnType::DATE => \Exment::carbonToArray(new \Carbon\Carbon('2020-07-01 03:00:00')),
        ]);
    }

    public function testDateNot()
    {
        $this->executeTestAllColumns(ColumnType::DATE, [
            ColumnType::DATE => [1],
        ], [
            ColumnType::DATE => [$this->getErrorMessage('date', ColumnType::DATE)],
        ]);
    }
    public function testDateNot2()
    {
        $this->executeTestAllColumns(ColumnType::DATE, [
            ColumnType::DATE => 'abc',
        ], [
            ColumnType::DATE => [$this->getErrorMessage('date', ColumnType::DATE)],
        ]);
    }
    public function testDateNot3()
    {
        $this->executeTestAllColumns(ColumnType::DATE, [
            ColumnType::DATE => '123',
        ], [
            ColumnType::DATE => [$this->getErrorMessage('date', ColumnType::DATE)],
        ]);
    }
    public function testDateNot4()
    {
        $this->executeTestAllColumns(ColumnType::DATE, [
            ColumnType::DATE => 123,
        ], [
            ColumnType::DATE => [$this->getErrorMessage('date', ColumnType::DATE)],
        ]);
    }




    // TIME ----------------------------------------------------
    public function testSuccessTime()
    {
        $this->executeTestAllColumns(ColumnType::TIME, [
            ColumnType::TIME => '03:00:00',
        ]);
    }
    public function testSuccessTime2()
    {
        $this->executeTestAllColumns(ColumnType::TIME, [
            ColumnType::TIME => '1234',
        ]);
    }
    public function testSuccessTime3()
    {
        $this->executeTestAllColumns(ColumnType::TIME, [
            ColumnType::TIME => '12:34',
        ]);
    }
    public function testSuccessTime4()
    {
        $this->executeTestAllColumns(ColumnType::TIME, [
            ColumnType::TIME => '123456',
        ]);
    }

    public function testTimeNot()
    {
        $this->executeTestAllColumns(ColumnType::TIME, [
            ColumnType::TIME => [1],
        ], [
            ColumnType::TIME => [$this->getErrorMessage('regex', ColumnType::TIME)],
        ]);
    }
    public function testTimeNot2()
    {
        $this->executeTestAllColumns(ColumnType::TIME, [
            ColumnType::TIME => 'abc',
        ], [
            ColumnType::TIME => [$this->getErrorMessage('regex', ColumnType::TIME)],
        ]);
    }
    public function testTimeNot3()
    {
        $this->executeTestAllColumns(ColumnType::TIME, [
            ColumnType::TIME => '123',
        ], [
            ColumnType::TIME => [$this->getErrorMessage('regex', ColumnType::TIME)],
        ]);
    }
    public function testTimeNot4()
    {
        $this->executeTestAllColumns(ColumnType::TIME, [
            ColumnType::TIME => 123,
        ], [
            ColumnType::TIME => [$this->getErrorMessage('regex', ColumnType::TIME)],
        ]);
    }
    public function testTimeNot5()
    {
        $this->executeTestAllColumns(ColumnType::TIME, [
            ColumnType::TIME => '45:56',
        ], [
            ColumnType::TIME => [$this->getErrorMessage('regex', ColumnType::TIME)],
        ]);
    }




    // DATETIME ----------------------------------------------------
    public function testSuccessDatetime()
    {
        $this->executeTestAllColumns(ColumnType::DATETIME, [
            ColumnType::DATETIME => '2020-07-01',
        ]);
    }

    public function testSuccessDatetime2()
    {
        $this->executeTestAllColumns(ColumnType::DATETIME, [
            ColumnType::DATETIME => '2020-07-01 03:00:00',
        ]);
    }

    public function testSuccessDatetime3()
    {
        $this->executeTestAllColumns(ColumnType::DATETIME, [
            ColumnType::DATETIME => new \Carbon\Carbon('2020-07-01'),
        ], [
        ], [
            ColumnType::DATETIME => \Exment::carbonToArray(new \Carbon\Carbon('2020-07-01')),
        ]);
    }

    public function testSuccessDatetime4()
    {
        $this->executeTestAllColumns(ColumnType::DATETIME, [
            ColumnType::DATETIME => new \Carbon\Carbon('2020-07-01 03:00:00'),
        ], [
        ], [
            ColumnType::DATETIME => \Exment::carbonToArray(new \Carbon\Carbon('2020-07-01 03:00:00')),
        ]);
    }

    public function testDatetimeNot()
    {
        $this->executeTestAllColumns(ColumnType::DATETIME, [
            ColumnType::DATETIME => [1],
        ], [
            ColumnType::DATETIME => [$this->getErrorMessage('date', ColumnType::DATETIME)],
        ]);
    }
    public function testDatetimeNot2()
    {
        $this->executeTestAllColumns(ColumnType::DATETIME, [
            ColumnType::DATETIME => 'abc',
        ], [
            ColumnType::DATETIME => [$this->getErrorMessage('date', ColumnType::DATETIME)],
        ]);
    }
    public function testDatetimeNot3()
    {
        $this->executeTestAllColumns(ColumnType::DATETIME, [
            ColumnType::DATETIME => '123',
        ], [
            ColumnType::DATETIME => [$this->getErrorMessage('date', ColumnType::DATETIME)],
        ]);
    }
    public function testDatetimeNot4()
    {
        $this->executeTestAllColumns(ColumnType::DATETIME, [
            ColumnType::DATETIME => 123,
        ], [
            ColumnType::DATETIME => [$this->getErrorMessage('date', ColumnType::DATETIME)],
        ]);
    }



    // SELECT ----------------------------------------------------
    public function testSuccessSelect()
    {
        $this->executeTestAllColumns(ColumnType::SELECT, [
            ColumnType::SELECT => 'foo',
        ]);
    }
    public function testSuccessSelect2()
    {
        $this->executeTestAllColumns(ColumnType::SELECT, [
            ColumnType::SELECT => 'foo,bar',
        ]);
    }
    public function testSuccessSelect3()
    {
        $this->executeTestAllColumns(ColumnType::SELECT, [
            ColumnType::SELECT => ['foo','bar'],
        ]);
    }
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
    public function testSuccessSelectValText()
    {
        $this->executeTestAllColumns(ColumnType::SELECT_VALTEXT, [
            ColumnType::SELECT_VALTEXT => 'foo',
        ]);
    }
    public function testSuccessSelectValText2()
    {
        $this->executeTestAllColumns(ColumnType::SELECT_VALTEXT, [
            ColumnType::SELECT_VALTEXT => 'FOO',
        ]);
    }
    public function testSuccessSelectValText3()
    {
        $this->executeTestAllColumns(ColumnType::SELECT_VALTEXT, [
            ColumnType::SELECT_VALTEXT => 'foo,bar',
        ]);
    }
    public function testSuccessSelectValText4()
    {
        $this->executeTestAllColumns(ColumnType::SELECT_VALTEXT, [
            ColumnType::SELECT_VALTEXT => 'FOO,bar',
        ]);
    }
    public function testSuccessSelectValText5()
    {
        $this->executeTestAllColumns(ColumnType::SELECT_VALTEXT, [
            ColumnType::SELECT_VALTEXT => ['foo', 'bar'],
        ]);
    }
    public function testSuccessSelectValText6()
    {
        $this->executeTestAllColumns(ColumnType::SELECT_VALTEXT, [
            ColumnType::SELECT_VALTEXT => ['FOO', 'bar'],
        ]);
    }

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
    public function testSuccessSelectTable()
    {
        $this->executeTestAllColumns(ColumnType::SELECT_TABLE, [
            ColumnType::SELECT_TABLE => '1',
        ]);
    }
    public function testSuccessSelectTable2()
    {
        $this->executeTestAllColumns(ColumnType::SELECT_TABLE, [
            ColumnType::SELECT_TABLE => '1,2',
        ]);
    }
    public function testSuccessSelectTable3()
    {
        $this->executeTestAllColumns(ColumnType::SELECT_TABLE, [
            ColumnType::SELECT_TABLE => ['1', '2'],
        ]);
    }

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
    public function testSuccessYesNo()
    {
        $this->executeTestAllColumns(ColumnType::YESNO, [
            ColumnType::YESNO => 1,
        ]);
    }
    public function testSuccessYesNo2()
    {
        $this->executeTestAllColumns(ColumnType::YESNO, [
            ColumnType::YESNO => '1',
        ]);
    }
    public function testSuccessYesNo3()
    {
        $this->executeTestAllColumns(ColumnType::YESNO, [
            ColumnType::YESNO => 0,
        ]);
    }
    public function testSuccessYesNo4()
    {
        $this->executeTestAllColumns(ColumnType::YESNO, [
            ColumnType::YESNO => '0',
        ]);
    }
    public function testSuccessYesNo5()
    {
        $this->executeTestAllColumns(ColumnType::YESNO, [
            ColumnType::YESNO => 'YES',
        ]);
    }
    public function testSuccessYesNo6()
    {
        $this->executeTestAllColumns(ColumnType::YESNO, [
            ColumnType::YESNO => 'yes',
        ]);
    }
    public function testSuccessYesNo7()
    {
        $this->executeTestAllColumns(ColumnType::YESNO, [
            ColumnType::YESNO => 'NO',
        ]);
    }
    public function testSuccessYesNo8()
    {
        $this->executeTestAllColumns(ColumnType::YESNO, [
            ColumnType::YESNO => 'no',
        ]);
    }
    public function testSuccessYesNo9()
    {
        $this->executeTestAllColumns(ColumnType::YESNO, [
            ColumnType::YESNO => true,
        ]);
    }
    public function testSuccessYesNo10()
    {
        $this->executeTestAllColumns(ColumnType::YESNO, [
            ColumnType::YESNO => false,
        ]);
    }
    public function testSuccessYesNo11()
    {
        $this->executeTestAllColumns(ColumnType::YESNO, [
            ColumnType::YESNO => 'true',
        ]);
    }
    public function testSuccessYesNo12()
    {
        $this->executeTestAllColumns(ColumnType::YESNO, [
            ColumnType::YESNO => 'false',
        ]);
    }

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
    public function testSuccessBoolean()
    {
        $this->executeTestAllColumns(ColumnType::BOOLEAN, [
            ColumnType::BOOLEAN => 'ok',
        ]);
    }
    public function testSuccessBoolean2()
    {
        $this->executeTestAllColumns(ColumnType::BOOLEAN, [
            ColumnType::BOOLEAN => 'OK',
        ]);
    }
    public function testSuccessBoolean3()
    {
        $this->executeTestAllColumns(ColumnType::BOOLEAN, [
            ColumnType::BOOLEAN => 'ng',
        ]);
    }
    public function testSuccessBoolean4()
    {
        $this->executeTestAllColumns(ColumnType::BOOLEAN, [
            ColumnType::BOOLEAN => 'NG',
        ]);
    }
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






    protected function executeTestEdit(array $values, array $errors = [], array $matches = [], $id = null)
    {
        $custom_value = CustomTable::getEloquent('custom_value_edit')->getValueModel($id);

        $this->executeTest($custom_value, $values, $errors, $matches);
    }

    protected function executeTestAllColumns($column_type, array $values, array $errors = [], array $matches = [])
    {
        $custom_column = $this->getCustomColumnModel($column_type);
        $custom_value = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST)->getValueModel();

        $this->executeTest($custom_value, $values, $errors, $matches);
    }

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

    protected function getErrorMessage($validatekey, $column, array $messages = [])
    {
        $array = array_merge($messages, ['attribute' => $column]);
        return trans("validation.$validatekey", $array);
    }

    // ...
    protected function login($id = null)
    {
        $this->be(LoginUser::find($id ?? 1));
    }
}
