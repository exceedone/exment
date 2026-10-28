<?php

namespace Exceedone\Exment\Tests\Unit;

use ExmentAdminCore\Admin\Auth\Database\Administrator;
use ExmentAdminCore\Admin\Show;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * admin_is_valid_url(): null-safe replacement for url()->isValidUrl().
 *
 * Background: UrlGenerator::isValidUrl() passes its argument straight into preg_match().
 * With a null value (nullable columns such as admin_users.avatar / admin_menu.uri) that is
 * E_DEPRECATED on PHP 8.1+ and a TypeError on PHP 9.
 *
 * These tests do not touch the database.
 */
class AdminIsValidUrlTest extends UnitTestBase
{
    /**
     * For every string the helper must answer exactly what the framework answers,
     * including protocol-relative URLs, '#' and mailto:, which Str::isUrl() would reject.
     *
     * @return void
     */
    public function testMatchesFrameworkForStrings()
    {
        $samples = [
            '',
            'avatar/abc.jpg',
            '/vendor/open-admin/AdminLTE/dist/img/user2-160x160.jpg',
            'https://example.com/a.png',
            'http://example.com',
            '//cdn.example.com/x.png',
            '#',
            'mailto:a@b.c',
            'tel:0123',
            'data:image/png;base64,AAA',
            'data/custom_table?view=abc',
        ];

        foreach ($samples as $sample) {
            $this->assertSame(
                URL::isValidUrl($sample),
                admin_is_valid_url($sample),
                "admin_is_valid_url('{$sample}') must match URL::isValidUrl()"
            );
        }

        $this->assertTrue(admin_is_valid_url('https://example.com/a.png'));
        $this->assertTrue(admin_is_valid_url('//cdn.example.com/x.png'));
        $this->assertTrue(admin_is_valid_url('#'));
        $this->assertFalse(admin_is_valid_url('avatar/abc.jpg'));
        $this->assertFalse(admin_is_valid_url(''));
    }

    /**
     * Non-string values are rejected without touching preg_match() (no deprecation).
     *
     * @return void
     */
    public function testRejectsNonStringsWithoutDeprecation()
    {
        $values = [null, 0, 1.5, false, true, [], new \stdClass()];

        [, $deprecations] = $this->captureDeprecations(function () use ($values) {
            foreach ($values as $value) {
                $this->assertFalse(admin_is_valid_url($value), 'non-string ' . gettype($value) . ' must be rejected');
            }
        });

        $this->assertSame([], $deprecations);
    }

    /**
     * Objects with __toString() keep working like the old implicit coercion did.
     *
     * @return void
     */
    public function testAcceptsStringable()
    {
        $this->assertTrue(admin_is_valid_url(Str::of('https://example.com/a.png')));
        $this->assertFalse(admin_is_valid_url(Str::of('avatar/abc.jpg')));
        $this->assertFalse(admin_is_valid_url(Str::of('')));
    }

    /**
     * admin_url(null) is what the sidebar calls for a parent menu without children.
     *
     * @return void
     */
    public function testAdminUrlWithNullHasNoDeprecation()
    {
        [$result, $deprecations] = $this->captureDeprecations(function () {
            return admin_url(null);
        });

        $this->assertSame(admin_url(''), $result);
        $this->assertSame([], $deprecations);
    }

    /**
     * Administrator::getAvatarAttribute(): the case reported by the customer.
     *
     * @return void
     */
    public function testAdministratorAvatar()
    {
        $default = config('admin.default_avatar') ?: '/vendor/open-admin/AdminLTE/dist/img/user2-160x160.jpg';

        [$avatars, $deprecations] = $this->captureDeprecations(function () {
            return [
                'null' => (new Administrator(['avatar' => null]))->getAttribute('avatar'),
                'url' => (new Administrator(['avatar' => 'https://example.com/me.png']))->getAttribute('avatar'),
            ];
        });

        $this->assertSame(admin_asset($default), $avatars['null']);
        $this->assertSame('https://example.com/me.png', $avatars['url']);
        $this->assertSame([], $deprecations);
    }

    /**
     * Show\Field::file(): an empty value used to reach basename(null) and
     * Storage::exists(null) (TypeError). Now it renders nothing, like image()/carousel().
     *
     * @return void
     */
    public function testShowFileWithEmptyValueRendersNothing()
    {
        foreach ([null, ''] as $value) {
            [$html, $deprecations] = $this->captureDeprecations(function () use ($value) {
                return $this->renderShowFile($value);
            });

            $this->assertStringNotContainsString('mailbox-attachments', $html);
            $this->assertSame([], $deprecations, 'value ' . var_export($value, true));
        }
    }

    /**
     * @return void
     */
    public function testShowFileWithUrlStillRendersAttachment()
    {
        $html = $this->renderShowFile('https://example.com/doc.pdf');

        $this->assertStringContainsString('mailbox-attachments', $html);
        $this->assertStringContainsString('href="https://example.com/doc.pdf"', $html);
        $this->assertStringContainsString('doc.pdf', $html);
    }

    /**
     * Render a Show with a single ->file() field on a plain model (no accessor, no DB).
     *
     * @param mixed $value
     * @return string
     */
    protected function renderShowFile($value): string
    {
        $model = new class () extends Model {
            protected $table = 'dummy_show_file';
            protected $guarded = [];
        };
        $model->setRawAttributes(['doc' => $value]);

        $show = new Show($model);
        $show->field('doc', 'Doc')->file();

        return (string) $show->render();
    }

    /**
     * Run $callback and return [its result, every "Passing null to parameter" deprecation it raised]
     * (PHP 8.1 null-to-internal-function, a TypeError on PHP 9).
     * Unrelated framework deprecations are ignored so the test stays focused.
     *
     * @param callable $callback
     * @return array{0: mixed, 1: array<string>}
     */
    protected function captureDeprecations(callable $callback): array
    {
        $captured = [];
        set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$captured) {
            if (str_contains($errstr, 'Passing null to parameter')) {
                $captured[] = $errstr . ' @ ' . basename($errfile) . ':' . $errline;
            }
            return true;
        }, E_DEPRECATED | E_USER_DEPRECATED);

        try {
            $result = $callback();
        } finally {
            restore_error_handler();
        }

        return [$result, $captured];
    }
}
