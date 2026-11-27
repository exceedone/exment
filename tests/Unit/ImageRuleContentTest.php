<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Validator\FileRule;
use Exceedone\Exment\Validator\ImageRule;
use Illuminate\Container\Container;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * ImageRule checks the uploaded file content is image, not only the file name's extension. (TC-FORM-08)
 */
#[RequiresPhpExtension('gd')]
#[RequiresPhpExtension('fileinfo')]
class ImageRuleContentTest extends TestCase
{
    /** @var string */
    protected $dir;

    /** @var Container|null */
    protected $prevContainer;

    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . 'Helpers.php';

        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'exment_image_rule_' . uniqid();
        mkdir($this->dir);

        $this->prevContainer = Container::getInstance();
        $container = new Container();
        $loader = new ArrayLoader();
        $loader->addMessages('en', 'validation', [
            'image' => 'The :attribute must be an image.',
            'mimes' => 'The :attribute must be a file of type: :values.',
        ]);
        $container->instance('translator', new Translator($loader, 'en'));
        Container::setInstance($container);
        Facade::clearResolvedInstances();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        Container::setInstance($this->prevContainer);
        Facade::clearResolvedInstances();

        parent::tearDown();
    }

    /**
     * Create real image file by GD. $type: png, jpg, gif, bmp
     */
    protected function makeImage(string $type, string $clientName): UploadedFile
    {
        $im = imagecreatetruecolor(2, 2);
        $path = $this->dir . DIRECTORY_SEPARATOR . uniqid() . '.bin';
        switch ($type) {
            case 'png':
                imagepng($im, $path);
                break;
            case 'jpg':
                imagejpeg($im, $path);
                break;
            case 'gif':
                imagegif($im, $path);
                break;
            case 'bmp':
                imagebmp($im, $path);
                break;
        }
        return new UploadedFile($path, $clientName, null, null, true);
    }

    protected function makeText(string $clientName, string $content = "<?php echo 'fake image';\n"): UploadedFile
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . uniqid() . '.bin';
        file_put_contents($path, $content);
        return new UploadedFile($path, $clientName, null, null, true);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function realImageProvider(): array
    {
        return [
            'jpg' => ['jpg', 'photo.jpg'],
            'jpeg' => ['jpg', 'photo.jpeg'],
            'JPG upper' => ['jpg', 'PHOTO.JPG'],
            'png' => ['png', 'photo.png'],
            'gif' => ['gif', 'photo.gif'],
            'bmp' => ['bmp', 'photo.bmp'],
            // other image type in the allowed list (png named .jpg) passes, because it is still image.
            'png named jpg' => ['png', 'photo.jpg'],
            'gif named png' => ['gif', 'photo.png'],
        ];
    }

    #[DataProvider('realImageProvider')]
    public function testRealImagePasses(string $type, string $clientName): void
    {
        $this->assertTrue((new ImageRule())->passes('image', $this->makeImage($type, $clientName)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function fakeImageProvider(): array
    {
        return [
            'php text named jpg' => ['fake_image.jpg'],
            'text named png' => ['fake.png'],
            'text named gif' => ['fake.gif'],
            'text named bmp' => ['fake.bmp'],
        ];
    }

    #[DataProvider('fakeImageProvider')]
    public function testNotImageContentWithImageExtensionFails(string $clientName): void
    {
        $this->assertFalse((new ImageRule())->passes('image', $this->makeText($clientName)));
    }

    public function testNotImageContentFailsWithImageMessage(): void
    {
        $factory = new Factory(Container::getInstance()->make('translator'));
        $validator = $factory->make(
            ['image' => $this->makeText('fake_image.jpg')],
            ['image' => [new ImageRule()]]
        );

        $this->assertTrue($validator->fails());
        $this->assertSame(['The image must be an image.'], $validator->errors()->get('image'));
    }

    public function testInvalidExtensionFailsAsBefore(): void
    {
        $rule = new ImageRule();
        // real image but not allowed extension
        $this->assertFalse($rule->passes('image', $this->makeImage('png', 'photo.txt')));
        $this->assertFalse($rule->passes('image', $this->makeText('evil.php')));
        // svg / webp are not in the allowed list (as before)
        $this->assertFalse($rule->passes('image', $this->makeText('icon.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>')));
        $this->assertFalse($rule->passes('image', $this->makeText('photo.webp')));
    }

    public function testMultipleValues(): void
    {
        $rule = new ImageRule();
        $this->assertTrue($rule->passes('image', [$this->makeImage('png', 'a.png'), $this->makeImage('jpg', 'b.jpg'), null]));
        $this->assertFalse($rule->passes('image', [$this->makeImage('png', 'a.png'), $this->makeText('b.jpg')]));
    }

    public function testNotUploadedFileValueAsBefore(): void
    {
        $rule = new ImageRule();
        // null / empty: checked by other rule
        $this->assertTrue($rule->passes('image', null));
        $this->assertTrue($rule->passes('image', ''));
        $this->assertTrue($rule->passes('image', []));
        $this->assertTrue($rule->passes('image', [null, '']));
        // file name string (ex: old file value, api file name): only extension
        $this->assertTrue($rule->passes('image', 'exist_file.png'));
        $this->assertTrue($rule->passes('image', 'path/to/exist_file.JPG'));
        $this->assertFalse($rule->passes('image', 'exist_file.txt'));
    }

    public function testFileRuleKeepsExtensionOnlyCheck(): void
    {
        // file column (accept_extensions) checks only extension, as before.
        $rule = new FileRule(['jpg', 'txt']);
        $this->assertTrue($rule->passes('file', $this->makeText('fake_image.jpg')));
        $this->assertTrue($rule->passes('file', $this->makeText('normal.txt')));
        $this->assertFalse($rule->passes('file', $this->makeText('evil.php')));
    }
}
