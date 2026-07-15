<?php

namespace Exceedone\Exment\Tests\Unit\Line;

use Exceedone\Exment\Services\Line\LineFlexBuilder;
use PHPUnit\Framework\TestCase;

/**
 * GĐ3: LineFlexBuilder — phần THUẦN (không DB). Bổ sung cho LineFlexValidityTest:
 * tách body_items, format chi tiết workflow mặc định, và cấu trúc nút footer.
 * (Phần "thay biến" ${...} do NotifyService::notifyLine làm -> test ở LineFlexNotifyTest.)
 */
class LineFlexBuilderTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!class_exists(LineFlexBuilder::class, false)) {
            require_once __DIR__ . '/../../../src/Services/Line/LineFlexBuilder.php';
        }
    }

    // -------------------------------------------------- parseBodyItems

    public function test_parseBodyItems_splits_label_and_format_on_first_equals(): void
    {
        $items = LineFlexBuilder::parseBodyItems("Trạng thái = \${workflow:status_name}");

        $this->assertCount(1, $items);
        $this->assertSame('Trạng thái', $items[0]['label']);
        $this->assertSame('${workflow:status_name}', $items[0]['format']);
    }

    public function test_parseBodyItems_keeps_equals_signs_inside_format(): void
    {
        // chỉ tách ở dấu '=' ĐẦU TIÊN -> format vẫn giữ '=' bên trong
        $items = LineFlexBuilder::parseBodyItems('Ghi chú = a=b=c');

        $this->assertSame('Ghi chú', $items[0]['label']);
        $this->assertSame('a=b=c', $items[0]['format']);
    }

    public function test_parseBodyItems_trims_whitespace_around_label_and_format(): void
    {
        $items = LineFlexBuilder::parseBodyItems("   Nhãn    =    giá trị   ");

        $this->assertSame('Nhãn', $items[0]['label']);
        $this->assertSame('giá trị', $items[0]['format']);
    }

    public function test_parseBodyItems_skips_lines_without_equals(): void
    {
        $items = LineFlexBuilder::parseBodyItems("dòng không có dấu bằng\nNhãn = giá trị");

        $this->assertCount(1, $items);
        $this->assertSame('Nhãn', $items[0]['label']);
    }

    public function test_parseBodyItems_skips_lines_with_empty_label(): void
    {
        $items = LineFlexBuilder::parseBodyItems(" = chỉ có giá trị\nNhãn = ok");

        $this->assertCount(1, $items);
        $this->assertSame('Nhãn', $items[0]['label']);
    }

    public function test_parseBodyItems_allows_empty_format(): void
    {
        // nhãn có, format rỗng -> vẫn giữ (giá trị rỗng sẽ bị lọc lúc thay biến, không phải ở đây)
        $items = LineFlexBuilder::parseBodyItems('Nhãn =');

        $this->assertCount(1, $items);
        $this->assertSame('Nhãn', $items[0]['label']);
        $this->assertSame('', $items[0]['format']);
    }

    public function test_parseBodyItems_handles_crlf_and_cr_line_endings(): void
    {
        $items = LineFlexBuilder::parseBodyItems("A = 1\r\nB = 2\rC = 3");

        $this->assertSame(['A', 'B', 'C'], array_column($items, 'label'));
        $this->assertSame(['1', '2', '3'], array_column($items, 'format'));
    }

    public function test_parseBodyItems_returns_empty_for_blank_input(): void
    {
        $this->assertSame([], LineFlexBuilder::parseBodyItems(''));
        $this->assertSame([], LineFlexBuilder::parseBodyItems("\n\n  \n"));
    }

    // -------------------------------------------------- workflowDetailFormats

    public function test_workflowDetailFormats_are_label_key_and_format_pairs(): void
    {
        $formats = LineFlexBuilder::workflowDetailFormats();

        $this->assertNotEmpty($formats);
        foreach ($formats as $pair) {
            $this->assertCount(2, $pair, 'Mỗi phần tử phải là [exmtrans_key, format].');
            [$labelKey, $format] = $pair;
            $this->assertIsString($labelKey);
            // format phải chứa biến ${...} để thay lúc gửi
            $this->assertMatchesRegularExpression('/\$\{.+\}/', $format);
        }
    }

    public function test_defaultTitle_is_a_format_with_status_and_value_variables(): void
    {
        $title = LineFlexBuilder::defaultTitle();

        $this->assertStringContainsString('${workflow:status_name}', $title);
        $this->assertStringContainsString('${value}', $title);
    }

    // -------------------------------------------------- buildBubble: nút footer

    public function test_buildBubble_renders_postback_button_for_workflow_action(): void
    {
        $bubble = LineFlexBuilder::buildBubble('Tiêu đề', [], [
            ['label' => 'Duyệt', 'data' => 'act=workflow&table=t&id=1&action=2'],
        ]);

        $button = $bubble['footer']['contents'][0];
        $this->assertSame('button', $button['type']);
        $this->assertSame('primary', $button['style']);
        $this->assertSame('postback', $button['action']['type']);
        $this->assertSame('act=workflow&table=t&id=1&action=2', $button['action']['data']);
        // displayText để LINE hiện bong bóng người dùng khi bấm
        $this->assertSame('Duyệt', $button['action']['displayText']);
    }

    public function test_buildBubble_renders_uri_button_as_link_style(): void
    {
        $bubble = LineFlexBuilder::buildBubble('Tiêu đề', [], [
            ['label' => 'Xem chi tiết', 'uri' => 'https://example.com/d/1'],
        ]);

        $button = $bubble['footer']['contents'][0];
        $this->assertSame('link', $button['style']);
        $this->assertSame('uri', $button['action']['type']);
        $this->assertSame('https://example.com/d/1', $button['action']['uri']);
        // nút uri KHÔNG có displayText (không phải postback)
        $this->assertArrayNotHasKey('displayText', $button['action']);
    }

    public function test_buildBubble_without_buttons_has_no_footer(): void
    {
        $bubble = LineFlexBuilder::buildBubble('Chỉ tiêu đề', [['label' => 'A', 'value' => 'B']], []);

        $this->assertArrayNotHasKey('footer', $bubble);
    }

    public function test_buildBubble_renders_rows_as_label_value_boxes(): void
    {
        $bubble = LineFlexBuilder::buildBubble('T', [['label' => 'Trạng thái', 'value' => 'Đã duyệt']], []);

        // body[0] = title, body[1] = row box
        $rowBox = $bubble['body']['contents'][1];
        $this->assertSame('box', $rowBox['type']);
        $this->assertSame('Trạng thái', $rowBox['contents'][0]['text']);
        $this->assertSame('Đã duyệt', $rowBox['contents'][1]['text']);
    }
}
