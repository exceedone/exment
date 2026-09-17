<?php

namespace Exceedone\Exment\Services\Line;

class LineFlexBuilder
{
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

    public static function postbackData(string $tableKey, $valueId, $actionId): string
    {
        return "act=workflow&table={$tableKey}&id={$valueId}&action={$actionId}";
    }

    public static function safetyPostbackData($eventId, string $status): string
    {
        return "act=safety&event={$eventId}&st={$status}";
    }

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

    public static function defaultTitle(): string
    {
        return '[${workflow:status_name}] ${value}';
    }

    public static function defaultBodyItems(): string
    {
        $lines = [];
        foreach (static::workflowDetailFormats() as [$labelKey, $format]) {
            $lines[] = exmtrans($labelKey) . ' = ' . $format;
        }
        return implode("\n", $lines);
    }

    /**
     * @param string $title
     * @param array $rows
     * @param array $buttons
     */
    public static function buildBubble(string $title, array $rows, array $buttons): array
    {
        $title = trim($title);
        $bodyContents = [];
        if ($title !== '') {
            $bodyContents[] = [
                'type' => 'text', 'text' => $title, 'weight' => 'bold', 'size' => 'md', 'wrap' => true,
            ];
        }

        foreach ($rows as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            $value = trim((string) ($row['value'] ?? ''));
            if ($label === '' && $value === '') {
                continue;
            }
            if ($label === '' || $value === '') {
                $bodyContents[] = [
                    'type' => 'text', 'text' => ($label === '' ? $value : $label),
                    'size' => 'sm', 'color' => '#888888', 'wrap' => true,
                ];
                continue;
            }
            $bodyContents[] = [
                'type' => 'box', 'layout' => 'horizontal', 'spacing' => 'sm',
                'contents' => [
                    ['type' => 'text', 'text' => $label, 'size' => 'sm', 'color' => '#888888', 'flex' => 4, 'wrap' => true, 'gravity' => 'top'],
                    ['type' => 'text', 'text' => $value, 'size' => 'sm', 'wrap' => true, 'flex' => 6, 'gravity' => 'top'],
                ],
            ];
        }

        if (empty($bodyContents)) {
            $bodyContents[] = ['type' => 'text', 'text' => '-', 'wrap' => true];
        }

        $footerContents = [];
        foreach ($buttons as $btn) {
            if (isset($btn['uri'])) {
                $action = ['type' => 'uri', 'label' => (string) $btn['label'], 'uri' => (string) $btn['uri']];
                $style = 'link';
            } else {
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
