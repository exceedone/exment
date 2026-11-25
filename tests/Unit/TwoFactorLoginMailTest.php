<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Services\Auth2factor\LoginContext;
use Exceedone\Exment\Services\Auth2factor\LoginMailTemplates;
use Illuminate\Http\Request;

/**
 * The mails of the 2-step verification say where the login came from (ISS00268): the IP address,
 * the country and the region (from the headers of a CDN, when the site says it has one), the
 * browser and the time. The templates still as Exment wrote them get the new lines.
 */
class TwoFactorLoginMailTest extends UnitTestBase
{
    /**
     * @param array<string, string> $headers
     */
    protected function request(array $headers = [], string $ip = '203.0.113.10'): Request
    {
        $server = ['REMOTE_ADDR' => $ip];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        return Request::create('/admin/auth/login', 'POST', [], [], [], $server);
    }

    public function testTheValuesOfTheMail(): void
    {
        $prms = LoginContext::prms($this->request(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36']));
        $this->assertSame('203.0.113.10', $prms['login_ip']);
        $this->assertSame('Chrome (Windows)', $prms['login_user_agent']);
        $this->assertMatchesRegularExpression('#^\d{4}/\d{2}/\d{2} \d{2}:\d{2}$#', $prms['login_datetime']);
        // no CDN said: the place is not known
        $this->assertSame(exmtrans('2factor.login_unknown'), $prms['login_location']);
    }

    public function testTheHeadersOfTheCdnOnlyWhenTheSiteHasOne(): void
    {
        $headers = ['CloudFront-Viewer-Country' => 'JP', 'CloudFront-Viewer-Country-Region-Name' => 'Tokyo'];

        config(['exment.login_location_headers' => false]);
        $this->assertSame(exmtrans('2factor.login_unknown'), LoginContext::prms($this->request($headers))['login_location'], 'a header anyone could send is not used');

        config(['exment.login_location_headers' => true]);
        app()->setLocale('en');
        $prms = LoginContext::prms($this->request($headers));
        $this->assertSame('Tokyo', $prms['login_region']);
        $this->assertContains($prms['login_country'], ['Japan', 'JP']);
        $this->assertStringEndsWith('Tokyo', $prms['login_location']);

        // Cloudflare; its "unknown" and Tor
        $this->assertContains(LoginContext::prms($this->request(['CF-IPCountry' => 'DE']))['login_country'], ['Germany', 'DE']);
        foreach (['XX', 'T1', 'not a code'] as $code) {
            $this->assertSame(exmtrans('2factor.login_unknown'), LoginContext::prms($this->request(['CF-IPCountry' => $code]))['login_country'], $code);
        }
    }

    public function testTheBrowser(): void
    {
        foreach ([
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0' => 'Edge (Windows)',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15' => 'Safari (macOS)',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1' => 'Safari (iOS)',
            'Mozilla/5.0 (X11; Linux x86_64; rv:133.0) Gecko/20100101 Firefox/133.0' => 'Firefox (Linux)',
            'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36' => 'Chrome (Android)',
            'curl/8.5.0' => null,
            '' => null,
        ] as $agent => $expected) {
            $this->assertSame($expected, LoginContext::browser($agent), $agent);
        }
    }

    public function testATemplateAsExmentWroteItGetsTheLines(): void
    {
        foreach (LoginMailTemplates::OLD as $key => $bodies) {
            foreach ($bodies as $locale => $old) {
                $new = LoginMailTemplates::withLogin($old, $locale);
                foreach (['${login_datetime}', '${login_ip}', '${login_location}', '${login_user_agent}'] as $value) {
                    $this->assertStringContainsString($value, $new, "$key $locale");
                }
                $head = strstr($old, '${valid_period_datetime}', true);
                $this->assertIsString($head);
                $this->assertSame($head, substr($new, 0, strlen($head)), "$key $locale: the text before stays");
            }
        }
    }

    public function testTheUpgradeChangesOnlyTheTemplatesAsExmentWroteThem(): void
    {
        $table = CustomTable::getEloquent(SystemTableName::MAIL_TEMPLATE);
        $templates = [];
        foreach ($table->getValueModel()->newQuery()->get() as $template) {
            $templates[strval($template->getValue('mail_key_name'))] = $template;
        }
        $this->assertArrayHasKey('verify_2factor', $templates);
        \DB::beginTransaction();
        try {
            $templates['verify_2factor']->setValue('mail_body', LoginMailTemplates::OLD['verify_2factor']['ja'])->save();
            $templates['verify_2factor_system']->setValue('mail_body', LoginMailTemplates::OLD['verify_2factor_system']['en'])->save();
            $templates['verify_2factor_google']->setValue('mail_body', 'わが社の文面 ${2factor_google_register_url}')->save();

            $this->assertSame(['verify_2factor', 'verify_2factor_system'], LoginMailTemplates::upgrade());

            $fresh = function (string $key) use ($table) {
                return strval($table->getValueModel()->newQuery()->get()->first(function ($template) use ($key) {
                    return $template->getValue('mail_key_name') === $key;
                })->getValue('mail_body'));
            };
            $this->assertStringContainsString('[IP アドレス]', $fresh('verify_2factor'));
            $this->assertStringContainsString('[IP address]', $fresh('verify_2factor_system'));
            $this->assertSame('わが社の文面 ${2factor_google_register_url}', $fresh('verify_2factor_google'), 'a changed template stays');
            // a second time: nothing more
            $this->assertSame([], LoginMailTemplates::upgrade());
        } finally {
            \DB::rollBack();
        }
    }
}
