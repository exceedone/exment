<?php

namespace Exceedone\Exment\Services\Line;

/**
 * Builds a LINE Flex Message bubble from a template (title + body_items) and dynamic buttons.
 * The methods are PURE (no DB access) for easy testing; variable substitution and
 * workflow action resolution are prepared by the caller (NotifyService::notifyLine)
 * and passed into buildBubble().
 */
class LineFlexBuilder
{
    /** Parses body_items: each "Label = format" line -> ['label'=>, 'format'=>]. Skips lines without '='. */
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

    /** Builds the data string for a postback button. */
    public static function postbackData(string $tableKey, $valueId, $actionId): string
    {
        return "act=workflow&table={$tableKey}&id={$valueId}&action={$actionId}";
    }

    /**
     * Default workflow detail fields for the Flex card, sharing the SAME variables as the
     * mail template workflow (${workflow:...}, ${created_user}). Each entry: [exmtrans_key, format].
     * The caller (NotifyService::notifyLine) runs exmtrans on the label and replaceWord on the value.
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
     * Default title for the Flex card: "[new status] record name" -> makes clear what the
     * workflow is doing (which record, at which stage). It is a format containing variables,
     * resolved on send via replaceWord.
     */
    public static function defaultTitle(): string
    {
        return '[${workflow:status_name}] ${value}';
    }

    /**
     * Default body_items content: the 5 standard workflow fields in "Label = format" form.
     * Used to prefill a new template (column default) and as a fallback. Labels follow the
     * current locale (exmtrans) so "the table is the source of truth" stays consistent with the card.
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
     * Assembles the Flex bubble.
     * @param string $title
     * @param array $rows    list of ['label'=>string,'value'=>string]
     * @param array $buttons list of ['label'=>string,'data'=>string]
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
            $bodyContents[] = [
                'type' => 'box', 'layout' => 'horizontal', 'spacing' => 'sm',
                'contents' => [
                    ['type' => 'text', 'text' => (string) $row['label'], 'size' => 'sm', 'color' => '#888888', 'flex' => 4, 'wrap' => true, 'gravity' => 'top'],
                    ['type' => 'text', 'text' => (string) $row['value'], 'size' => 'sm', 'wrap' => true, 'flex' => 6, 'gravity' => 'top'],
                ],
            ];
        }

        if (empty($bodyContents)) {
            $bodyContents[] = ['type' => 'text', 'text' => '-', 'wrap' => true];
        }

        $footerContents = [];
        foreach ($buttons as $btn) {
            if (isset($btn['uri'])) {
                // link-opening button (e.g. "View details") - link style, no displayText bubble
                $action = ['type' => 'uri', 'label' => (string) $btn['label'], 'uri' => (string) $btn['uri']];
                $style = 'link';
            } else {
                // button that executes a workflow action (postback)
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
