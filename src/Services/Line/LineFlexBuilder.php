<?php

namespace Exceedone\Exment\Services\Line;

/**
 * Dựng Flex Message bubble cho LINE từ template (title + body_items) và nút động.
 * Các method là THUẦN (không DB) để dễ test; phần thay biến / lấy action workflow
 * do nơi gọi (NotifyService::notifyLine) chuẩn bị rồi truyền vào buildBubble().
 */
class LineFlexBuilder
{
    /** Tách body_items: mỗi dòng "Nhãn = format" -> ['label'=>, 'format'=>]. Bỏ dòng không có '='. */
    public static function parseBodyItems(string $raw): array
    {
        $items = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            if (strpos($line, '=') === false) {
                continue;
            }
            [$label, $format] = explode('=', $line, 2);
            $label = trim($label);
            $format = trim($format);
            if ($label === '') {
                continue;
            }
            $items[] = ['label' => $label, 'format' => $format];
        }
        return $items;
    }

    /** Chuỗi data cho nút postback. */
    public static function postbackData(string $tableKey, $valueId, $actionId): string
    {
        return "act=workflow&table={$tableKey}&id={$valueId}&action={$actionId}";
    }

    /**
     * Các trường chi tiết workflow mặc định cho thẻ Flex, dùng CHUNG biến với mail
     * template workflow (${workflow:...}, ${created_user}). Mỗi phần tử: [exmtrans_key, format].
     * Nơi gọi (NotifyService::notifyLine) sẽ exmtrans nhãn + replaceWord giá trị.
     */
    public static function workflowDetailFormats(): array
    {
        return [
            ['common.workflow_status', '${workflow:status_name}'],
            ['common.created_user',    '${created_user}'],
            ['workflow.action_name',   '${workflow:action_name}'],
            ['workflow.executed_user', '${workflow:action_user}'],
            ['common.comment',         '${workflow:comment}'],
        ];
    }

    /**
     * Title mặc định cho thẻ Flex: "[trạng thái mới] tên bản ghi" -> nêu rõ WF
     * đang làm gì (bản ghi nào, đang ở giai đoạn nào). Là format chứa biến,
     * resolve khi gửi qua replaceWord.
     */
    public static function defaultTitle(): string
    {
        return '[${workflow:status_name}] ${value}';
    }

    /**
     * Nội dung body_items mặc định: 5 trường workflow chuẩn dạng "Nhãn = format".
     * Dùng để điền sẵn template mới (column default) + làm fallback. Nhãn theo
     * locale hiện tại (exmtrans) -> "bảng là nguồn chính" khớp với thẻ.
     */
    public static function defaultBodyItems(): string
    {
        $lines = [];
        foreach (static::workflowDetailFormats() as [$labelKey, $format]) {
            $lines[] = exmtrans($labelKey) . ' = ' . $format;
        }
        return implode("\n", $lines);
    }

    /**
     * Ráp bubble Flex.
     * @param string $title
     * @param array $rows    list ['label'=>string,'value'=>string]
     * @param array $buttons list ['label'=>string,'data'=>string]
     */
    public static function buildBubble(string $title, array $rows, array $buttons): array
    {
        $bodyContents = [[
            'type' => 'text', 'text' => $title, 'weight' => 'bold', 'size' => 'md', 'wrap' => true,
        ]];

        foreach ($rows as $row) {
            // layout=horizontal + wrap: nhãn dài (vd "Current Status") tự xuống dòng,
            // không bị cắt "..." như baseline. gravity=top để canh đầu dòng khi wrap.
            $bodyContents[] = [
                'type' => 'box', 'layout' => 'horizontal', 'spacing' => 'sm',
                'contents' => [
                    ['type' => 'text', 'text' => (string) $row['label'], 'size' => 'sm', 'color' => '#888888', 'flex' => 4, 'wrap' => true, 'gravity' => 'top'],
                    ['type' => 'text', 'text' => (string) $row['value'], 'size' => 'sm', 'wrap' => true, 'flex' => 6, 'gravity' => 'top'],
                ],
            ];
        }

        $footerContents = [];
        foreach ($buttons as $btn) {
            if (isset($btn['uri'])) {
                // nút mở link (vd "Xem chi tiết") - kiểu link, không tạo bong bóng displayText
                $action = ['type' => 'uri', 'label' => (string) $btn['label'], 'uri' => (string) $btn['uri']];
                $style = 'link';
            } else {
                // nút thực thi action workflow (postback)
                $action = ['type' => 'postback', 'label' => (string) $btn['label'], 'data' => (string) $btn['data'], 'displayText' => (string) $btn['label']];
                $style = 'primary';
            }
            $footerContents[] = [
                'type' => 'button', 'style' => $style, 'height' => 'sm',
                'action' => $action,
            ];
        }

        $bubble = [
            'type' => 'bubble',
            'body' => ['type' => 'box', 'layout' => 'vertical', 'spacing' => 'md', 'contents' => $bodyContents],
        ];
        if (!empty($footerContents)) {
            $bubble['footer'] = ['type' => 'box', 'layout' => 'vertical', 'spacing' => 'sm', 'contents' => $footerContents];
        }
        return $bubble;
    }
}
