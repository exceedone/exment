<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Services\Meili\MeiliSearchService;
use PHPUnit\Framework\TestCase;

class AttachmentPermissionTest extends TestCase
{
    public function testAttachmentTextNeverBypassesTheParentRecordPermission(): void
    {
        $hits = [
            ['table_name' => 'customer', 'value_id' => 101, 'label' => 'Allowed', 'attachments' => [
                ['file_uuid' => 'a', 'name' => 'public.pdf', 'text' => '公開資料 日本語★'],
            ]],
            ['table_name' => 'customer', 'value_id' => 102, 'label' => 'Denied', 'attachments' => [
                ['file_uuid' => 'b', 'name' => 'secret.pdf', 'text' => '機密資料 TOP-SECRET'],
            ]],
        ];

        $visible = MeiliSearchService::filterAccessibleHits($hits, [
            'customer' => [101 => true],
        ], 10);

        $this->assertSame([101], array_column($visible, 'value_id'));
        $this->assertSame('公開資料 日本語★', $visible[0]['attachments'][0]['text']);
        $this->assertStringNotContainsString('TOP-SECRET', json_encode($visible, JSON_UNESCAPED_UNICODE));
    }
}
