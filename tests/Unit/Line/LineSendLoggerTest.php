<?php

namespace Exceedone\Exment\Tests\Unit\Line;

use Exceedone\Exment\Services\Line\LineSendLogger;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure logic of LineSendLogger (no DB / Laravel involvement).
 * The body-hiding branch (save_body = false) calls exmtrans(), so it is covered in LineSendLogTest (Feature).
 */
class LineSendLoggerTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!class_exists(LineSendLogger::class, false)) {
            require_once __DIR__ . '/../../../src/Services/Line/LineSendLogger.php';
        }
    }

    public function test_buildBody_serializes_messages_as_readable_json(): void
    {
        $messages = [['type' => 'text', 'text' => 'Đơn hàng đã duyệt']];

        $body = LineSendLogger::buildBody($messages, true);

        $this->assertStringContainsString('Đơn hàng đã duyệt', $body);
        $this->assertSame($messages, json_decode($body, true));
    }

    public function test_resolveStatus_maps_push_result(): void
    {
        $this->assertSame(
            LineSendLogger::STATUS_SUCCESS,
            LineSendLogger::resolveStatus(['ok' => true, 'status' => 200])
        );
        $this->assertSame(
            LineSendLogger::STATUS_FAILED,
            LineSendLogger::resolveStatus(['ok' => false, 'status' => 401])
        );
        $this->assertSame(LineSendLogger::STATUS_FAILED, LineSendLogger::resolveStatus([]));
    }

    public function test_formatError_keeps_status_and_body_on_failure(): void
    {
        $error = LineSendLogger::formatError([
            'ok'     => false,
            'status' => 401,
            'raw'    => '{"message":"Authentication failed"}',
        ]);

        $this->assertStringContainsString('401', $error);
        $this->assertStringContainsString('Authentication failed', $error);
    }

    public function test_formatError_is_null_on_success(): void
    {
        $this->assertNull(LineSendLogger::formatError(['ok' => true, 'status' => 200, 'raw' => '{}']));
    }
}
