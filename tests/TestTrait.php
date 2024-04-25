<?php

namespace Exceedone\Exment\Tests;

use Exceedone\Exment\Database\Eloquent\ExtendedBuilder;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\CustomTable;
use DMS\PHPUnitExtensions\ArraySubset\ArraySubsetAsserts;

// cannot call PHPUnit\Framework\Constraint\ArraySubset.
// use ArrayAccess;
// use PHPUnit\Framework\InvalidArgumentException;
// use PHPUnit\Framework\Constraint\ArraySubset;

trait TestTrait
{
    use ArraySubsetAsserts;

    /**
     * Assert that the response is a superset of the given JSON.
     *
     * @param  array  $data1
     * @param  array  $data2
     * @param  bool  $strict
     * @return $this
     */
    public function assertJsonExment(array $data1, $data2, $strict = false)
    {
        self::assertArraySubset($data1, $data2, $strict);
        // cannot call PHPUnit\Framework\Constraint\ArraySubset.
        // if(function_exists($this, 'assertArraySubset')){
        //     self::assertArraySubset($data1, $data2, $strict);
        // }
        // else{
        //     self::assertArraySubsetExm($data1, $data2, $strict);
        // }

        return $this;
    }

    protected function assertMatch($value1, $value2)
    {
        return $this->_assertMatch($value1, $value2, true);
    }


    protected function assertMatchRegex(string $pattern, string $string, string $message = ''): void
    {
        if (method_exists($this, 'assertMatchesRegularExpression')) {
            $this->assertMatchesRegularExpression($pattern, $string, $message);
            return;
        }
        /* @phpstan-ignore-next-line delete next line. it's deprecated function */
        $this->assertRegExp($pattern, $string, $message);
    }

    protected function assertNotMatch($value1, $value2)
    {
        return $this->_assertMatch($value1, $value2, false);
    }

    protected function _assertMatch($value1, $value2, bool $isTrue)
    {
        $messageV1 = is_array($value1) ? json_encode($value1) : $value1;
        $messageV2 = is_array($value2) ? json_encode($value2) : $value2;

        $result = isMatchString($messageV1, $messageV2);
        $this->assertTrue($result === $isTrue, "value1 is $messageV1, but value2 is $messageV2. Expect result is " . ($isTrue ? 'match' : 'not match') . '.');

        return $this;
    }


    /**
     * Check post's Response
     *
     * @param mixed $response
     * @param string|null $expectUrl
     * @return void
     */
    protected function assertPostResponse($response, ?string $expectUrl)
    {
        $statusCode = $response->getStatusCode();
        $this->assertTrue(in_array($statusCode, [200, 302]), "Status code is {$statusCode}.");

        if ($statusCode == 302) {
            $this->assertMatch($response->getTargetUrl(), $expectUrl);
        }
    }

    /**
     * Skip test temporarily.
     *
     * @param $skipMatch
     * @param string|null $messsage
     * @return void
     * @throws \Exception
     */
    protected function skipTempTestIfTrue($skipMatch, string $messsage = null)
    {
        $result = null;
        if ($skipMatch instanceof \Closure) {
            $result = $skipMatch();
        } elseif (is_bool($skipMatch)) {
            $result = $skipMatch;
        } else {
            throw new \Exception('skipTempTestIfTrue is only bool or Closure');
        }

        if ($result) {
            $this->markTestSkipped('This function is temporarily skipped. ' . $messsage);
        }
    }

    /**
     * Skip test everytime.
     *
     * @param string $messsage showing message why this test is skipped.
     * @return void
     */
    protected function skipTempTest(string $messsage = null)
    {
        $this->markTestSkipped('This function is temporarily skipped. ' . $messsage);
    }

    /**
     * Initialize all test
     *
     * @return void
     */
    protected function initAllTest()
    {
        System::clearCache();
        \Exceedone\Exment\Middleware\Morph::defineMorphMap();
        \Exceedone\Exment\Middleware\ExmentDebug::handleLog();
        getModelName(SystemTableName::USER);
        getModelName(SystemTableName::ORGANIZATION);
    }


    /**
     * Check custom value's permission after getting api
     *
     * @param CustomTable $custom_table
     * @param array $ids
     * @param \Closure|null $filterCallback
     * @return void
     */
    protected function checkCustomValuePermission(CustomTable $custom_table, $ids, ?\Closure $filterCallback = null)
    {
        // get all ids
        $allIds = \DB::table(getDBTableName($custom_table))->select('id')->pluck('id');
        $query = $custom_table->getValueModel()->withoutGlobalScopes();

        if ($filterCallback) {
            $filterCallback($query);
        }
        $all_custom_values = $query->findMany($allIds);

        foreach ($all_custom_values as $all_custom_value) {
            // if find target user ids, check has permisison
            /** @var mixed $all_custom_value */
            $hasPermission = in_array($all_custom_value->id, $ids);
            $hasPermissionString = $hasPermission ? 'true' : 'false';

            $this->assertTrue($hasPermission === $custom_table->hasPermissionData($all_custom_value->id), "id {$all_custom_value->id}'s permission expects {$hasPermissionString}, but wrong.");
        }
    }


    protected function getTextDirPath(): string
    {
        $dir = storage_path('app/tests');
        \Exment::makeDirectory($dir);

        return $dir;
    }

    protected function getTextFilePath($fileName = 'file.txt'): string
    {
        $dir = $this->getTextDirPath();

        // create file
        $file = path_join($dir, $fileName);
        if (!\File::exists($file)) {
            \File::put($file, TestDefine::FILE_BASE64);
        }
        return $file;
    }

    protected function getTextImagePath($imageName = 'image.png')
    {
        $dir = $this->getTextDirPath();
        // create file
        $file = path_join($dir, $imageName);
        if (!\File::exists($file)) {
            // convert to base64. This string is 1*1 rad color's image
            $f = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsIAAA7CARUoSoAAAAANSURBVBhXY3gro/IfAAVUAi3GPZKdAAAAAElFTkSuQmCC');
            \File::put($file, $f);
        }
        return $file;
    }

    protected function getTextFileObject($fileName = 'file.txt')
    {
        $file = $this->getTextFilePath($fileName);
        return \File::get($file);
    }

    protected function getTextImageObject($imageName = 'image.png')
    {
        $file = $this->getTextImagePath($imageName);
        return \File::get($file);
    }


    protected function callProtectedMethod($obj, $methodName, ...$args)
    {
        $method = new \ReflectionMethod(get_class($obj), $methodName);
        $method->setAccessible(true);
        return $method->invoke($obj, ...$args);
    }

    protected function callStaticProtectedMethod($className, $methodName, ...$args)
    {
        $method = new \ReflectionMethod($className, $methodName);
        $method->setAccessible(true);
        return $method->invokeArgs(null, $args);
    }

    // /**
    //  * Asserts that an array has a specified subset.
    //  * (Moved method because it was removed in PHPUnit9)
    //  *
    //  * @param array|ArrayAccess|mixed[] $subset
    //  * @param array|ArrayAccess|mixed[] $array
    //  *
    //  * @throws ExpectationFailedException
    //  * @throws InvalidArgumentException
    //  * @throws Exception
    //  */
    // public static function assertArraySubsetExm($subset, $array, bool $checkForObjectIdentity = false, string $message = ''): void
    // {
    //     if (!(is_array($subset) || $subset instanceof ArrayAccess)) {
    //         throw InvalidArgumentException::create(
    //             1,
    //             'array or ArrayAccess'
    //         );
    //     }

    //     if (!(is_array($array) || $array instanceof ArrayAccess)) {
    //         throw InvalidArgumentException::create(
    //             2,
    //             'array or ArrayAccess'
    //         );
    //     }

    //     $constraint = new ArraySubset($subset, $checkForObjectIdentity);

    //     static::assertThat($array, $constraint, $message);
    // }
}
