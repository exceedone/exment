<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Illuminate\Support\Facades\Cache;

/**
 * The cache of Exment (EXMENT_USE_CACHE) is kept under a generation read from the database:
 * clearing it on one server makes every server of the site work its values out again
 * (ISS02843).
 */
class CacheGenerationTest extends UnitTestBase
{
    /** @var mixed */
    private $useCacheBefore;

    /** @var object|null */
    private $generationRow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useCacheBefore = config('exment.use_cache');
        config(['cache.default' => 'array', 'exment.use_cache' => true, 'exment.features_locked' => '']);
        // No transaction: System::cache() does not write the store while one is open (it may be rolled back),
        // so the generation row is restored by hand.
        $this->generationRow = \DB::table('systems')->where('system_name', Define::SYSTEM_KEY_CACHE_GENERATION)->first();
        System::clearCache();
    }

    protected function tearDown(): void
    {
        $row = \DB::table('systems')->where('system_name', Define::SYSTEM_KEY_CACHE_GENERATION);
        if ($this->generationRow) {
            $row->update(['system_value' => $this->generationRow->system_value]);
        } else {
            $row->delete();
        }
        config(['exment.use_cache' => $this->useCacheBefore, 'exment.features_locked' => '']);
        System::clearRequestSession();
        parent::tearDown();
    }

    /**
     * Another server of the site: it clears its own store and renews the generation in the
     * database; the store of this server is not touched.
     */
    private function anotherServerClears(): void
    {
        \DB::table('systems')->updateOrInsert(
            ['system_name' => Define::SYSTEM_KEY_CACHE_GENERATION],
            ['system_value' => 'other' . uniqid()]
        );
    }

    /**
     * This server, a second later (the generation is read again after a second), in a new request.
     */
    private function nextRequestLater(): void
    {
        System::clearRequestSession();
        $read = new \ReflectionProperty(System::class, 'cacheGenerationReadAt');
        $read->setAccessible(true);
        $read->setValue(null, 0.0);
    }

    public function testAValueIsKeptFromOneRequestToTheNext(): void
    {
        $calls = 0;
        $load = function () use (&$calls) {
            $calls++;
            return 'value ' . $calls;
        };
        $this->assertSame('value 1', System::cache('cache_generation_test', $load));
        System::clearRequestSession();
        $this->assertSame('value 1', System::cache('cache_generation_test', $load), 'the next request reads the store');
        $this->assertSame(1, $calls);
    }

    public function testWhatAnotherServerClearedIsWorkedOutAgain(): void
    {
        $calls = 0;
        $load = function () use (&$calls) {
            $calls++;
            return 'value ' . $calls;
        };
        $this->assertSame('value 1', System::cache('cache_generation_test', $load));

        $this->anotherServerClears();
        $this->nextRequestLater();

        $this->assertSame('value 2', System::cache('cache_generation_test', $load), 'the value kept before the other server cleared is not used');
        System::clearRequestSession();
        $this->assertSame('value 2', System::cache('cache_generation_test', $load));
        $this->assertSame(2, $calls);
    }

    public function testClearingRenewsTheGenerationForTheOtherServers(): void
    {
        $before = System::cacheGeneration();
        System::clearCache();
        $after = System::cacheGeneration();
        $this->assertNotSame($before, $after);
        $this->assertSame($after, \DB::table('systems')->where('system_name', Define::SYSTEM_KEY_CACHE_GENERATION)->value('system_value'));
    }

    public function testOneKeyIsForgottenWithoutANewGeneration(): void
    {
        System::cache('cache_generation_test', function () {
            return 'kept';
        });
        $before = System::cacheGeneration();
        System::clearCache('cache_generation_test');
        $this->assertSame($before, System::cacheGeneration());
        $this->assertSame('again', System::cache('cache_generation_test', function () {
            return 'again';
        }));
    }

    public function testClearingLeavesTheRestOfTheStore(): void
    {
        // not Exment's: the count of wrong passwords, the signal to restart the queue...
        Cache::put('not_of_exment', 'stays', 600);
        System::cache('cache_generation_test', function () {
            return 'kept';
        });

        System::clearCache();

        $this->assertSame('stays', Cache::get('not_of_exment'));
        System::clearRequestSession();
        $this->assertSame('again', System::cache('cache_generation_test', function () {
            return 'again';
        }));
    }

    public function testAFileStoreKeepsItInAFolderOfItsOwnAndEmptiesOnlyThat(): void
    {
        $path = sys_get_temp_dir() . '/exment_cache_generation_' . uniqid('', true);
        config([
            'cache.default' => 'file',
            'cache.stores.file' => ['driver' => 'file', 'path' => $path],
            'cache.stores.' . System::FILE_STORE => null,
        ]);
        Cache::forgetDriver('file');
        Cache::forgetDriver(System::FILE_STORE);
        try {
            Cache::put('not_of_exment', 'stays', 600);
            System::cache('cache_generation_test', function () {
                return 'kept';
            });
            $this->assertDirectoryExists($path . DIRECTORY_SEPARATOR . 'exment');
            System::clearRequestSession();
            $this->assertSame('kept', System::cache('cache_generation_test', function () {
                return 'again';
            }), 'read from the files');

            System::clearCache();

            $this->assertSame('stays', Cache::get('not_of_exment'));
            $this->assertSame([], \File::allFiles($path . DIRECTORY_SEPARATOR . 'exment'), 'the files of the generation before are removed');
            System::clearRequestSession();
            $this->assertSame('again', System::cache('cache_generation_test', function () {
                return 'again';
            }));
        } finally {
            \File::deleteDirectory($path);
            config(['cache.default' => 'array', 'cache.stores.' . System::FILE_STORE => null]);
            Cache::forgetDriver('file');
            Cache::forgetDriver(System::FILE_STORE);
        }
    }

    public function testWithoutTheCacheNothingIsKeptAndTheGenerationIsNotAsked(): void
    {
        config(['exment.use_cache' => false]);
        System::clearRequestSession();
        $queries = 0;
        \DB::listen(function () use (&$queries) {
            $queries++;
        });
        $calls = 0;
        $load = function () use (&$calls) {
            $calls++;
            return 'value ' . $calls;
        };
        System::cache('cache_generation_test', $load);
        System::clearRequestSession();
        System::cache('cache_generation_test', $load);
        $counted = $queries;
        $this->assertSame(2, $calls, 'worked out in each request');
        $this->assertSame(0, $counted, 'no query for the generation');
    }

    public function testTheCommandClearsForEveryServer(): void
    {
        System::cache('cache_generation_test', function () {
            return 'kept';
        });
        $before = System::cacheGeneration();
        $this->assertSame(0, \Artisan::call('exment:cache-clear'));
        $this->assertNotSame($before, System::cacheGeneration());
        System::clearRequestSession();
        $this->assertSame('again', System::cache('cache_generation_test', function () {
            return 'again';
        }));
    }
}
