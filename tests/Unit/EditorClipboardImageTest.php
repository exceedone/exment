<?php

namespace Exceedone\Exment\Tests\Unit {
    use App\Plugins\PmPortal\LightApiController;
    use Exceedone\Exment\ColumnItems\CustomColumns\Editor;
    use Exceedone\Exment\Model\CustomTable;
    use Exceedone\Exment\Model\Define;
    use Exceedone\Exment\Model\File;
    use Exceedone\Exment\Model\System;
    use Illuminate\Config\Repository;
    use Illuminate\Container\Container;
    use Illuminate\Foundation\Application;
    use Illuminate\Support\Facades\Facade;
    use Illuminate\Support\Facades\Log;
    use Illuminate\Support\Facades\Storage;
    use Illuminate\Translation\ArrayLoader;
    use Illuminate\Translation\Translator;
    use Illuminate\Validation\Factory;
    use Illuminate\Validation\ValidationException;
    use Mockery;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;
    use ReflectionClass;
    use ReflectionMethod;
    use ReflectionProperty;
    use RuntimeException;

    final class ClipboardImageFixture
    {
        public const TEMP_UUID = '11111111-2222-3333-4444-555555555555';

        public const FILE_UUID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

        public static $session;

        public static array $temp = [];

        public static array $uploads = [];

        public static array $documents = [];

        public static array $queued = [];

        public static array $warnings = [];

        public static int $puts = 0;

        public static bool $failUpload = false;

        public static bool $throwUpload = false;

        public static bool $failTempDelete = false;

        public static function tempUrl(): string
        {
            return 'http://fixture.invalid/admin/tmpfiles/'.self::TEMP_UUID;
        }

        public static function fileUrl(): string
        {
            return 'http://fixture.invalid/admin/files/'.self::FILE_UUID;
        }
    }

    final class ClipboardImageFile
    {
        public function __construct(public string $uuid, public string $path) {}

        public function saveDocumentModel(ClipboardImageRow $row, string $name): void
        {
            ClipboardImageFixture::$documents[] = [$row->id, $name];
        }
    }

    final class ClipboardImageRow
    {
        public $id = null;

        public $custom_table;

        public array $value = [];

        public array $files = [];

        public Editor $editor;

        public int $transactions = 0;

        public bool $failAfterSave = false;

        public bool $rolledBack = false;

        public function file_uuids(array $file): void
        {
            $this->files[] = $file;
        }

        public function setValueStrictly(array $value): void
        {
            $this->value = $value;
        }

        public function getConnection()
        {
            return new class($this)
            {
                public function __construct(private ClipboardImageRow $row) {}

                public function transaction(callable $save): void
                {
                    $this->row->transactions++;
                    try {
                        $save();
                        if ($this->row->failAfterSave) {
                            throw new RuntimeException('Fixture transaction failed.');
                        }
                    } catch (\Throwable $error) {
                        $this->row->rolledBack = true;
                        throw $error;
                    }
                }
            };
        }

        public function save(): void
        {
            (new ReflectionProperty(Editor::class, 'value'))->setValue($this->editor, $this->value['description']);
            $this->value['description'] = $this->editor->saving();
            $this->id ??= 77;
            $this->editor->saved();
        }
    }

    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    class EditorClipboardImageTest extends TestCase
    {
        private Container $previousContainer;

        private $previousFacade;

        private Editor $editor;

        private ClipboardImageRow $row;

        private $table;

        protected function setUp(): void
        {
            parent::setUp();
            $this->previousContainer = Container::getInstance();
            $this->previousFacade = Facade::getFacadeApplication();
            $app = new Application;
            $app->instance('config', new Repository(['admin' => ['upload' => ['disk' => 'fixture_upload']]]));
            $translator = new Translator(new ArrayLoader, 'en');
            $app->instance('translator', $translator);
            $app->instance('validator', new Factory($translator, $app));
            Facade::setFacadeApplication($app);
            Facade::clearResolvedInstances();
            Storage::swap(Mockery::mock());
            $logger = Mockery::mock();
            $logger->shouldReceive('warning')->andReturnUsing(static function ($message, $context) {
                ClipboardImageFixture::$warnings[] = [$message, $context];
            });
            Log::swap($logger);
            require_once dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Services'
                .DIRECTORY_SEPARATOR.'Helpers.php';
            require_once dirname(__DIR__).DIRECTORY_SEPARATOR.'fixtures'
                .DIRECTORY_SEPARATOR.'EditorClipboardImageEnvironment.php';

            ClipboardImageFixture::$puts = 0;
            ClipboardImageFixture::$documents = [];
            ClipboardImageFixture::$queued = [];
            ClipboardImageFixture::$uploads = [];
            ClipboardImageFixture::$warnings = [];
            ClipboardImageFixture::$failUpload = false;
            ClipboardImageFixture::$throwUpload = false;
            ClipboardImageFixture::$failTempDelete = false;
            ClipboardImageFixture::$temp = [ClipboardImageFixture::TEMP_UUID => base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aG1sAAAAASUVORK5CYII='
            )];
            ClipboardImageFixture::$session = new class
            {
                public bool $owned = true;

                public function has(string $key): bool
                {
                    return $this->owned && $key === ClipboardImageFixture::TEMP_UUID;
                }

                public function get(string $key)
                {
                    return $this->has($key) ? 'clipboard.png' : null;
                }
            };
            $temp = Mockery::mock();
            $temp->shouldReceive('exists')->andReturnUsing(static fn ($path) => isset(ClipboardImageFixture::$temp[$path]));
            $temp->shouldReceive('get')->andReturnUsing(static fn ($path) => ClipboardImageFixture::$temp[$path]);
            $temp->shouldReceive('put')->andReturnUsing(static function ($path, $data) {
                ClipboardImageFixture::$temp[$path] = $data;

                return true;
            });
            $temp->shouldReceive('delete')->andReturnUsing(static function ($path) {
                if (ClipboardImageFixture::$failTempDelete) {
                    return false;
                }
                unset(ClipboardImageFixture::$temp[$path]);

                return true;
            });
            $uploads = Mockery::mock();
            $uploads->shouldReceive('put')->andReturnUsing(static function ($path, $data) {
                if (ClipboardImageFixture::$throwUpload) {
                    ClipboardImageFixture::$uploads[$path] = substr($data, 0, 8);
                    throw new RuntimeException('Fixture image write threw.');
                }
                if (ClipboardImageFixture::$failUpload) {
                    return false;
                }
                ClipboardImageFixture::$uploads[$path] = $data;

                return true;
            });
            $uploads->shouldReceive('exists')->andReturnUsing(static fn ($path) => isset(ClipboardImageFixture::$uploads[$path]));
            $uploads->shouldReceive('size')->andReturnUsing(static fn ($path) => strlen(ClipboardImageFixture::$uploads[$path]));
            $uploads->shouldReceive('get')->andReturnUsing(static fn ($path) => ClipboardImageFixture::$uploads[$path]);
            $uploads->shouldReceive('delete')->andReturnUsing(static function ($path) {
                unset(ClipboardImageFixture::$uploads[$path]);

                return true;
            });
            Storage::shouldReceive('disk')->with(Define::DISKNAME_TEMP_UPLOAD)->andReturn($temp);
            Storage::shouldReceive('disk')->with('fixture_upload')->andReturn($uploads);

            $files = Mockery::mock('alias:'.File::class);
            $files->shouldReceive('saveFileInfo')->andReturnUsing(static function ($type, $path) {
                ClipboardImageFixture::$puts++;

                return new ClipboardImageFile(ClipboardImageFixture::FILE_UUID, $path);
            });
            $system = Mockery::mock('alias:'.System::class);
            $system->shouldReceive('requestSession')->andReturnUsing(static function ($key, $value = null) {
                if ($value !== null) {
                    ClipboardImageFixture::$queued = $value;
                }

                return ClipboardImageFixture::$queued;
            });
            $this->table = Mockery::mock('alias:'.CustomTable::class);
            $this->table->table_name = 'fixture_issues';
            $this->row = new ClipboardImageRow;
            $this->row->custom_table = $this->table;
            $this->editor = (new ReflectionClass(Editor::class))->newInstanceWithoutConstructor();
            $this->row->editor = $this->editor;
            $column = (object) ['column_name' => 'description', 'column_type' => 'editor', 'column_item' => $this->editor];
            $this->table->custom_columns_cache = [$column];
            foreach (['custom_table' => $this->table, 'custom_value' => $this->row, 'custom_column' => $column] as $name => $value) {
                (new ReflectionProperty(Editor::class, $name))->setValue($this->editor, $value);
            }
        }

        protected function tearDown(): void
        {
            try {
                Mockery::close();
            } finally {
                Facade::clearResolvedInstances();
                Facade::setFacadeApplication($this->previousFacade);
                Container::setInstance($this->previousContainer);
                parent::tearDown();
            }
        }

        private function promote(string $value): string
        {
            return (new ReflectionMethod(Editor::class, 'savedFileInEditor'))->invoke($this->editor, $value);
        }

        public function test_markdown_images_are_bound_to_the_newly_saved_issue(): void
        {
            $value = 'Before ![clipboard]('.ClipboardImageFixture::tempUrl().') after';
            $saved = $this->promote($value);
            $this->assertSame('Before ![clipboard]('.ClipboardImageFixture::fileUrl().') after', $saved);
            $this->assertSame(1, ClipboardImageFixture::$puts);
            $this->assertCount(1, $this->row->files);
            $this->assertArrayHasKey(ClipboardImageFixture::TEMP_UUID, ClipboardImageFixture::$temp);
            $this->assertSame([], ClipboardImageFixture::$documents);
            $this->row->id = 77;
            $this->editor->saved();
            $this->assertSame([[77, 'clipboard.png']], ClipboardImageFixture::$documents);
            $this->assertArrayNotHasKey(ClipboardImageFixture::TEMP_UUID, ClipboardImageFixture::$temp);
            $this->assertSame(
                ['url' => ClipboardImageFixture::fileUrl(), 'uuid' => ClipboardImageFixture::FILE_UUID],
                $this->editor->getPastedImageUrls($this->row)[ClipboardImageFixture::tempUrl()]
            );
        }

        public function test_duplicate_markdown_references_store_one_attachment(): void
        {
            $one = '![clipboard]('.ClipboardImageFixture::tempUrl().')';
            $saved = $this->promote($one."\n".$one);
            $this->assertSame(2, substr_count($saved, ClipboardImageFixture::fileUrl()));
            $this->assertSame(1, ClipboardImageFixture::$puts);
            $this->row->id = 77;
            $this->editor->saved();
            $this->editor->saved();
            $this->assertCount(1, ClipboardImageFixture::$documents);
        }

        public function test_existing_html_image_behavior_is_preserved(): void
        {
            $saved = $this->promote('<p><img src="'.ClipboardImageFixture::tempUrl().'" alt="clipboard"></p>');
            $this->assertStringContainsString('data-exment-file-uuid="'.ClipboardImageFixture::FILE_UUID.'"', $saved);
            $this->assertStringContainsString('src=""', $saved);
            $this->row->id = 77;
            $this->editor->saved();
            $this->assertSame([[77, 'clipboard.png']], ClipboardImageFixture::$documents);
        }

        public function test_public_form_html_images_still_attach_from_their_own_session(): void
        {
            $url = 'http://fixture.invalid/public/'.ClipboardImageFixture::FILE_UUID
                .'/tmpfiles/'.ClipboardImageFixture::TEMP_UUID;
            $saved = $this->promote('<img src="'.$url.'">');
            $this->assertStringContainsString('data-exment-file-uuid="'.ClipboardImageFixture::FILE_UUID.'"', $saved);
            $this->row->id = 77;
            $this->editor->saved();
            $this->assertSame([[77, 'clipboard.png']], ClipboardImageFixture::$documents);
        }

        public function test_expired_html_images_fail_instead_of_saving_broken_markup(): void
        {
            ClipboardImageFixture::$temp = [];
            $this->expectException(ValidationException::class);
            $this->promote('<img src="'.ClipboardImageFixture::tempUrl().'">');
        }

        public function test_foreign_html_images_cannot_be_attached(): void
        {
            ClipboardImageFixture::$session->owned = false;
            $this->expectException(ValidationException::class);
            $this->promote('<img src="'.ClipboardImageFixture::tempUrl().'">');
        }

        public function test_failed_temp_cleanup_is_logged_without_invalidating_a_saved_attachment(): void
        {
            ClipboardImageFixture::$failTempDelete = true;
            $urls = $this->persist('![clipboard]('.ClipboardImageFixture::tempUrl().')');
            $this->assertSame(ClipboardImageFixture::fileUrl(), $urls['description'][ClipboardImageFixture::tempUrl()]['url']);
            $this->assertSame([[77, 'clipboard.png']], ClipboardImageFixture::$documents);
            $this->assertFalse($this->row->rolledBack);
            $this->assertCount(1, ClipboardImageFixture::$warnings);
            $this->assertArrayHasKey(ClipboardImageFixture::TEMP_UUID, ClipboardImageFixture::$temp);
        }

        public function test_failed_image_storage_rolls_back_without_discarding_the_temporary_image(): void
        {
            $this->requireLightApiController();
            ClipboardImageFixture::$failUpload = true;
            try {
                $this->persist('![clipboard]('.ClipboardImageFixture::tempUrl().')');
                $this->fail('A failed image write must not be reported as a saved attachment.');
            } catch (RuntimeException $error) {
                $this->assertSame('Failed to store an editor image.', $error->getMessage());
                $this->assertTrue($this->row->rolledBack);
                $this->assertSame([], ClipboardImageFixture::$documents);
                $this->assertSame([], ClipboardImageFixture::$uploads);
                $this->assertArrayHasKey(ClipboardImageFixture::TEMP_UUID, ClipboardImageFixture::$temp);
            }
        }

        public function test_a_throwing_partial_image_write_is_cleaned_up_during_rollback(): void
        {
            $this->requireLightApiController();
            ClipboardImageFixture::$throwUpload = true;
            try {
                $this->persist('![clipboard]('.ClipboardImageFixture::tempUrl().')');
                $this->fail('A partial image write must not leave an orphaned upload.');
            } catch (RuntimeException $error) {
                $this->assertSame('Fixture image write threw.', $error->getMessage());
                $this->assertTrue($this->row->rolledBack);
                $this->assertSame([], ClipboardImageFixture::$documents);
                $this->assertSame([], ClipboardImageFixture::$uploads);
                $this->assertArrayHasKey(ClipboardImageFixture::TEMP_UUID, ClipboardImageFixture::$temp);
            }
        }

        public function test_plain_text_and_permanent_markdown_urls_are_unchanged(): void
        {
            $value = 'Text ![existing]('.ClipboardImageFixture::fileUrl().')';
            $this->assertSame($value, $this->promote($value));
            $this->assertSame(0, ClipboardImageFixture::$puts);
        }

        public function test_foreign_temporary_images_are_rejected_before_reading_bytes(): void
        {
            ClipboardImageFixture::$session->owned = false;
            try {
                $this->promote('![foreign]('.ClipboardImageFixture::tempUrl().')');
                $this->fail('A session must own the temporary image being attached.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('description', $error->errors());
                $this->assertSame(0, ClipboardImageFixture::$puts);
            }
        }

        public function test_expired_temporary_images_fail_validation(): void
        {
            ClipboardImageFixture::$temp = [];
            $this->expectException(ValidationException::class);
            $this->promote('![expired]('.ClipboardImageFixture::tempUrl().')');
        }

        public function test_non_image_temporary_content_is_rejected(): void
        {
            ClipboardImageFixture::$temp[ClipboardImageFixture::TEMP_UUID] = '<script>fixture</script>';
            $this->expectException(ValidationException::class);
            $this->promote('![invalid]('.ClipboardImageFixture::tempUrl().')');
        }

        public function test_cached_column_items_do_not_attach_images_to_another_row(): void
        {
            $this->row->id = 77;
            $this->promote('![clipboard]('.ClipboardImageFixture::tempUrl().')');
            $other = new ClipboardImageRow;
            $other->id = 88;
            $other->custom_table = $this->table;
            (new ReflectionProperty(Editor::class, 'custom_value'))->setValue($this->editor, $other);
            $this->promote('A different row without an image');
            $this->editor->saved();
            $this->assertSame([], ClipboardImageFixture::$documents);
            $this->assertSame([], $this->editor->getPastedImageUrls($other));
            (new ReflectionProperty(Editor::class, 'custom_value'))->setValue($this->editor, $this->row);
            $this->editor->saved();
            $this->assertSame([[77, 'clipboard.png']], ClipboardImageFixture::$documents);
        }

        public function test_repeated_saving_pass_preserves_the_pending_attachment(): void
        {
            $saved = $this->promote('![clipboard]('.ClipboardImageFixture::tempUrl().')');
            $this->assertSame($saved, $this->promote($saved));
            $this->row->id = 77;
            $this->editor->saved();
            $this->assertCount(1, ClipboardImageFixture::$documents);
        }

        public function test_native_api_saves_images_and_returns_authoritative_url_mappings(): void
        {
            $urls = $this->persist('![clipboard]('.ClipboardImageFixture::tempUrl().')');
            $this->assertSame(1, $this->row->transactions);
            $this->assertSame('![clipboard]('.ClipboardImageFixture::fileUrl().')', $this->row->value['description']);
            $this->assertSame(ClipboardImageFixture::fileUrl(), $urls['description'][ClipboardImageFixture::tempUrl()]['url']);
            $this->assertSame([[77, 'clipboard.png']], ClipboardImageFixture::$documents);
        }

        public function test_failed_image_save_restores_temporary_bytes_and_removes_only_its_new_file(): void
        {
            $this->requireLightApiController();
            $bytes = ClipboardImageFixture::$temp[ClipboardImageFixture::TEMP_UUID];
            ClipboardImageFixture::$uploads['existing/attachment'] = 'Existing attachment bytes';
            $this->row->failAfterSave = true;
            try {
                $this->persist('![clipboard]('.ClipboardImageFixture::tempUrl().')');
                $this->fail('A failed transaction must remain a failed save.');
            } catch (RuntimeException $error) {
                $this->assertSame('Fixture transaction failed.', $error->getMessage());
                $this->assertTrue($this->row->rolledBack);
                $this->assertSame($bytes, ClipboardImageFixture::$temp[ClipboardImageFixture::TEMP_UUID]);
                $this->assertSame(['existing/attachment' => 'Existing attachment bytes'], ClipboardImageFixture::$uploads);
            }
        }

        public function test_native_api_without_images_preserves_the_original_save_path(): void
        {
            $this->assertSame([], $this->persist('Ordinary body'));
            $this->assertSame(0, $this->row->transactions);
            $this->assertSame(0, ClipboardImageFixture::$puts);
        }

        /**
         * Load the controller the native API tests go through, or skip the
         * test when the PMPortal plugin is not installed.
         *
         * Call it at the top of a test, before any try/catch: markTestSkipped()
         * throws a PHPUnit exception that extends RuntimeException, so raised
         * inside catch (RuntimeException $error) the skip is caught there and
         * reported as a failed assertion on its message.
         */
        private function requireLightApiController(): void
        {
            $source = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'
                .DIRECTORY_SEPARATOR.'plugins'.DIRECTORY_SEPARATOR.'PmPortal'.DIRECTORY_SEPARATOR.'LightApiController.php';
            if (! is_file($source)) {
                $this->markTestSkipped('PMPortal is required for native API image persistence tests.');
            }
            require_once $source;
        }

        private function persist(string $value): array
        {
            $this->requireLightApiController();

            return (new ReflectionMethod(LightApiController::class, 'persistRow'))
                ->invoke(new LightApiController, $this->table, $this->row, ['description' => $value]);
        }
    }
}
