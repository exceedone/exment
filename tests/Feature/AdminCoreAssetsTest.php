<?php

namespace Exceedone\Exment\Tests\Feature;

/**
 * The JS libraries of the admin framework (exceedone/exment-admin-core 1.0.6, ISS00403):
 * no old ones with known vulnerabilities.
 */
class AdminCoreAssetsTest extends FeatureTestBase
{
    protected function asset(string $path): string
    {
        return base_path('vendor/exceedone/exment-admin-core/resources/assets/' . $path);
    }

    public function testAxiosIsVersion1(): void
    {
        $head = substr((string)file_get_contents($this->asset('axios/axios.min.js')), 0, 200);
        $this->assertMatchesRegularExpression('/Axios v1\.(2[1-9]|[3-9][0-9])\.\d+/', $head);
    }

    public function testSelect2Is4013(): void
    {
        $head = substr((string)file_get_contents($this->asset('AdminLTE/plugins/select2/select2.full.min.js')), 0, 200);
        $this->assertStringContainsString('4.0.13', $head);
        $this->assertFileExists($this->asset('AdminLTE/plugins/select2/i18n/ja.js'));
    }

    public function testNoBootstrap3Js(): void
    {
        $this->assertFileDoesNotExist($this->asset('AdminLTE/bootstrap/js/bootstrap.min.js'));
        // the pages load Bootstrap 5
        $this->assertStringContainsString('Bootstrap v5.', substr((string)file_get_contents($this->asset('open-admin/js/bootstrap.bundle.min.js')), 0, 200));
    }
}
