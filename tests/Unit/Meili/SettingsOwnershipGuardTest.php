<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use PHPUnit\Framework\TestCase;

/**
 * updateSettings replaces the whole relevance configuration of an index -
 * searchable weighting, filterable attributes, synonyms, stop words. Run
 * against an index another installation owns it overwrites that installation's
 * settings, which is the same class of damage exment:meili-index and
 * exment:meili-reconcile already refuse.
 *
 * Checked against the source, because reaching these lines needs a Meilisearch.
 */
class SettingsOwnershipGuardTest extends TestCase
{
    use MeiliSourceTrait;

    public function testTheSettingsCommandRefusesAForeignIndex(): void
    {
        $this->assertCallOrder(
            $this->methodBody('Console/MeiliSettingsCommand.php', 'public function handle('),
            'assertIndexOwned',
            'updateSettings',
            'exment:meili-settings must check who owns the index before writing settings to it'
        );
    }

    public function testTheSettingsJobRefusesAForeignIndex(): void
    {
        $this->assertCallOrder(
            $this->methodBody('Jobs/ApplyMeiliSettingsJob.php', 'public function handle('),
            'MeiliIndexOwnership::claim',
            'updateSettings',
            'ApplyMeiliSettingsJob must claim the index before writing settings to it'
        );
    }
}
