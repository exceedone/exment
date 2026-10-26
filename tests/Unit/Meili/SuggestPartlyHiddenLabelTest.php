<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Services\Meili\GlobalSearch\HeaderSuggester;
use Exceedone\Exment\Services\Meili\MeiliSearchService;
use PHPUnit\Framework\TestCase;

/**
 * A table label is a composite - "title + linked partner", say - so a viewer
 * who may not read the linked half still may read the rest of it.
 *
 * Deciding on the label alone cost such a viewer every suggestion, including
 * for records they can read completely, and comparing the label with its
 * highlight markers stripped also lost records whose own content contains that
 * marker literal. Both are decided in permittedSnippet().
 */
class SuggestPartlyHiddenLabelTest extends TestCase
{
    /** The markers carry a per-process nonce, so they cannot be class constants. */
    private function pre(): string
    {
        return MeiliSearchService::highlightPre();
    }

    private function post(): string
    {
        return MeiliSearchService::highlightPost();
    }

    /** @param array<string,mixed> $hit */
    private function snippet(array $hit, object $table, ?object $model, string $label): ?string
    {
        $suggester = (new \ReflectionClass(HeaderSuggester::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(HeaderSuggester::class, 'permittedSnippet');
        $method->setAccessible(true);

        return $method->invoke($suggester, $hit, $table, $model, $label);
    }

    /**
     * A table exposing the given freeword columns as plain (non linked) text.
     *
     * @param array<int, string> $names
     */
    private function tableWithReadableColumns(array $names): object
    {
        $columns = array_map(fn ($n) => (object) ['column_name' => $n, 'column_type' => 'text'], $names);

        return new class ($columns) {
            /** @param array<int, object> $columns */
            public function __construct(private array $columns)
            {
            }

            /** @return \Illuminate\Support\Collection<int, object> */
            public function getFreewordSearchColumns(): \Illuminate\Support\Collection
            {
                return collect($this->columns);
            }
        };
    }

    /**
     * The keyword matched the readable half of the label and the field that
     * half comes from. The record is one the viewer may open, so it belongs in
     * the suggestions even though the label also names a record they may not.
     */
    public function testAReadableFieldStillAnswersWhenTheLabelIsOnlyPartlyReadable(): void
    {
        $hit = [
            // what the document stores: the real linked partner name
            'label' => 'ミライ建設 本社ビル 設備保守契約 株式会社ミライ建設',
            'formatted' => [
                'label' => $this->pre() . 'ミライ建設' . $this->post() . ' 本社ビル 設備保守契約 株式会社ミライ建設',
                'fields' => ['title' => $this->pre() . 'ミライ建設' . $this->post() . ' 本社ビル 設備保守契約'],
            ],
            'matches' => [
                'label' => [['start' => 0, 'length' => 5]],
                'fields.title' => [['start' => 0, 'length' => 5]],
            ],
        ];

        $snippet = $this->snippet(
            $hit,
            $this->tableWithReadableColumns(['title']),
            null,
            // what this viewer sees instead: the linked half is withheld
            'ミライ建設 本社ビル 設備保守契約 (権限がありません)'
        );

        $this->assertSame(
            $this->pre() . 'ミライ建設' . $this->post() . ' 本社ビル 設備保守契約',
            $snippet,
            'the readable field carried the keyword, so the hit must be shown'
        );
    }

    /**
     * The other half of the same rule: nothing readable carried the keyword, so
     * the match can only come from the part that is hidden and showing the
     * record would confirm it exists.
     */
    public function testAHitIsStillDroppedWhenOnlyTheHiddenHalfMatched(): void
    {
        $hit = [
            'label' => '基本取引覚書 2026年度 ZZTEST',
            'formatted' => [
                'label' => '基本取引覚書 2026年度 ' . $this->pre() . 'ZZTEST' . $this->post(),
                'fields' => ['partner_id' => $this->pre() . 'ZZTEST' . $this->post()],
            ],
            'matches' => [
                'label' => [['start' => 17, 'length' => 6]],
                'fields.partner_id' => [['start' => 0, 'length' => 6]],
            ],
        ];

        $snippet = $this->snippet(
            $hit,
            // partner_id is not offered as a readable freeword column here
            $this->tableWithReadableColumns(['title']),
            null,
            '基本取引覚書 2026年度 (権限がありません)'
        );

        $this->assertNull($snippet, 'the keyword only matched content this viewer may not read');
    }

    /**
     * The readability check compares the raw indexed label with the one the
     * viewer may see. Comparing the highlighted label with its markers stripped
     * also strips the same literal out of record content, so a record carrying
     * it never looked readable and vanished from the suggestions.
     */
    public function testARecordContainingTheMarkerLiteralIsStillSuggested(): void
    {
        $name = 'PREFIX' . $this->pre() . 'INJECT' . $this->post();
        $hit = [
            'label' => $name,
            'formatted' => ['label' => $this->pre() . 'PREFIX' . $this->post() . $this->pre() . 'INJECT' . $this->post()],
            'matches' => ['label' => [['start' => 0, 'length' => 6]]],
        ];

        $snippet = $this->snippet($hit, $this->tableWithReadableColumns([]), null, $name);

        $this->assertNotNull($snippet, 'the viewer sees exactly this label, so the hit is readable');
        $this->assertStringContainsString('PREFIX', (string) $snippet);
    }

    /** The ordinary case keeps working: label fully readable and matched. */
    public function testAFullyReadableLabelAnswersAsBefore(): void
    {
        $hit = [
            'label' => '日本電機サービス株式会社',
            'formatted' => ['label' => $this->pre() . '日本電機サービス' . $this->post() . '株式会社'],
            'matches' => ['label' => [['start' => 0, 'length' => 8]]],
        ];

        $snippet = $this->snippet($hit, $this->tableWithReadableColumns([]), null, '日本電機サービス株式会社');

        $this->assertSame($this->pre() . '日本電機サービス' . $this->post() . '株式会社', $snippet);
    }
}
