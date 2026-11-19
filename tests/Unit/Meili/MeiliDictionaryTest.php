<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Model\MeiliDictionary;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for the relevance dictionary builders (synonyms + stop words).
 */
class MeiliDictionaryTest extends TestCase
{
    public function testSplitTermsHandlesCommaNewlineAndFullwidth(): void
    {
        $this->assertSame(
            ['contract', 'agreement', '契約'],
            MeiliDictionary::splitTerms("contract, agreement\n契約")
        );
        // Full-width comma / Japanese comma also split; blanks dropped.
        $this->assertSame(['a', 'b'], MeiliDictionary::splitTerms('a、 ，b ,'));
        $this->assertSame([], MeiliDictionary::splitTerms('  '));
        $this->assertSame([], MeiliDictionary::splitTerms(null));
    }

    public function testSynonymsMapIsMutualWithinAGroup(): void
    {
        $map = MeiliDictionary::buildSynonymsMap([
            ['word' => '契約', 'synonyms' => 'contract, agreement'],
        ]);

        // Every term maps to the others (mutual).
        $this->assertSame(['contract', 'agreement'], $map['契約']);
        $this->assertSame(['契約', 'agreement'], $map['contract']);
        $this->assertSame(['契約', 'contract'], $map['agreement']);
    }

    public function testSynonymsMapMergesAcrossGroups(): void
    {
        $map = MeiliDictionary::buildSynonymsMap([
            ['word' => '顧客', 'synonyms' => 'customer'],
            ['word' => 'customer', 'synonyms' => 'client'],
        ]);

        // 'customer' appears in both groups -> merged unique.
        $this->assertContains('顧客', $map['customer']);
        $this->assertContains('client', $map['customer']);
    }

    public function testSynonymsMapSkipsSingleTermGroups(): void
    {
        $map = MeiliDictionary::buildSynonymsMap([
            ['word' => 'lonely', 'synonyms' => ''],
        ]);
        $this->assertSame([], $map);
    }

    public function testStopWordsFlattenedUnique(): void
    {
        $words = MeiliDictionary::buildStopWords([
            ['word' => 'の, は, を'],
            ['word' => "が\nの"], // duplicate の
        ]);

        $this->assertSame(['の', 'は', 'を', 'が'], $words);
    }
}
