<?php

namespace Exceedone\Exment\Tests\Unit\Line;

use Exceedone\Exment\Services\Line\LineFlexBuilder;
use Exceedone\Exment\Services\Line\LineMessagingClient;
use Exceedone\Exment\Services\Line\LineWorkflowAction;
use PHPUnit\Framework\TestCase;

class LineFlexValidityTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $src = __DIR__ . '/../../../src/Services/Line/';
        if (!class_exists(LineFlexBuilder::class, false)) {
            require_once $src . 'LineFlexBuilder.php';
        }
        if (!class_exists(LineMessagingClient::class, false)) {
            require_once $src . 'LineMessagingClient.php';
        }
        if (!class_exists(LineWorkflowAction::class, false)) {
            require_once $src . 'LineWorkflowAction.php';
        }
    }

    /** Collect the text of every "text" component in a Flex tree (full deep traversal). */
    private function collectTextValues($node, array &$out): void
    {
        if (!is_array($node)) {
            return;
        }
        if (($node['type'] ?? null) === 'text') {
            $out[] = $node['text'] ?? null;
        }
        foreach ($node as $child) {
            if (is_array($child)) {
                $this->collectTextValues($child, $out);
            }
        }
    }

    public function test_buildBubble_empty_title_must_not_emit_empty_text_component(): void
    {
        $bubble = LineFlexBuilder::buildBubble('', [], []);

        $this->assertNotEmpty($bubble['body']['contents'], 'Flex body.contents must not be empty');

        $texts = [];
        $this->collectTextValues($bubble, $texts);
        foreach ($texts as $t) {
            $this->assertIsString($t, 'text component must have a text property (string)');
            $this->assertNotSame('', trim($t), 'LINE forbids empty "text" components -> would be rejected with 400');
        }
    }

    public function test_flex_altText_must_be_non_empty_and_within_400(): void
    {
        $bubble = LineFlexBuilder::buildBubble('Some title', [], []);

        $msg = LineMessagingClient::flex('', $bubble);
        $this->assertNotSame('', trim((string) $msg['altText']), 'altText must not be empty');

        $long = str_repeat('あ', 1000);
        $msg2 = LineMessagingClient::flex($long, $bubble);
        $this->assertLessThanOrEqual(400, mb_strlen($msg2['altText']), 'altText must be <= 400 characters');
        $this->assertNotSame('', trim((string) $msg2['altText']));
    }

    public function test_buildBubble_normal_case_still_valid(): void
    {
        $rows = [
            ['label' => 'Status', 'value' => 'Approved'],
        ];
        $buttons = [
            ['label' => 'OK', 'data' => LineFlexBuilder::postbackData('estimate', 12, 34)],
            ['label' => 'Detail', 'uri' => 'https://example.com/x'],
        ];
        $bubble = LineFlexBuilder::buildBubble('Card title', $rows, $buttons);

        $texts = [];
        $this->collectTextValues($bubble, $texts);
        $this->assertContains('Card title', $texts);
        $this->assertContains('Status', $texts);
        $this->assertContains('Approved', $texts);

        $this->assertCount(2, $bubble['footer']['contents']);
        $this->assertSame('postback', $bubble['footer']['contents'][0]['action']['type']);
        $this->assertSame('uri', $bubble['footer']['contents'][1]['action']['type']);

        foreach ($texts as $t) {
            $this->assertNotSame('', trim((string) $t));
        }
    }

    public function test_postback_round_trip(): void
    {
        $data = LineFlexBuilder::postbackData('estimate', 12, 34);
        $parsed = LineWorkflowAction::parsePostback($data);
        $this->assertSame('workflow', $parsed['act']);
        $this->assertSame('estimate', $parsed['table']);
        $this->assertSame('12', $parsed['id']);
        $this->assertSame('34', $parsed['action']);
    }
}
