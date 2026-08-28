<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

/**
 * "Relation search" (関連データ検索) link on data list rows and on the detail screen.
 *
 * Relation search only targets undeleted data, so the link must be hidden for
 * soft-deleted (trashed) records: on the data list rows shown with
 * "_scope_=trashed", and on the detail screen opened with "?trashed=1".
 * For active records the link must keep appearing when the table has relation
 * tables, and tables without relation tables must keep having no link at all.
 *
 * This test creates its own relation (metadata row only, no DDL) and its own
 * organization records and rolls everything back, so it does not depend on
 * `exment:inittest` data.
 */
class TrashedRelationSearchIconTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));

        // soft delete is the default; make sure the env does not force hard delete.
        \Config::set('exment.delete_force_custom_value', false);
    }

    // ------------------------------------------------------------------ //
    //  Data list (DefaultGrid row actions)                               //
    // ------------------------------------------------------------------ //

    /**
     * Table with relation tables, active record -> relation search icon is shown.
     */
    public function testIconShownOnActiveRow(): void
    {
        $this->createOrganizationUserRelation();
        $org = $this->createOrganization();

        $content = $this->getDecodedContent(admin_urls('data', SystemTableName::ORGANIZATION));

        $this->assertStringContainsString($org->getValue('organization_code'), $content, 'created organization should be listed');
        $this->assertStringContainsString($this->relationSearchUrl($org), $content, 'relation search link should be shown for an active row');
    }

    /**
     * Table with relation tables, soft-deleted record -> relation search icon is hidden
     * on the trashed scope list (the row itself and its other actions keep rendering).
     */
    public function testIconHiddenOnTrashedRow(): void
    {
        $this->createOrganizationUserRelation();
        $org = $this->createOrganization();

        $org->delete();
        $this->assertNotNull(
            CustomTable::getEloquent(SystemTableName::ORGANIZATION)->getValueModel()->withTrashed()->find($org->id),
            'organization should be soft deleted'
        );

        $content = $this->getDecodedContent(admin_urls_query('data', SystemTableName::ORGANIZATION, ['_scope_' => 'trashed']));

        $this->assertStringContainsString($org->getValue('organization_code'), $content, 'trashed organization should be listed on trashed scope');
        $this->assertStringContainsString("/{$org->id}/restoreClick", $content, 'restore action should be rendered for the trashed row');
        $this->assertStringNotContainsString($this->relationSearchUrl($org), $content, 'relation search link must be hidden for a trashed row');
    }

    /**
     * Table without relation tables -> no relation search icon on any row (unchanged behavior).
     */
    public function testIconAbsentWithoutRelationTables(): void
    {
        $custom_table = CustomTable::getEloquent(SystemTableName::MAIL_TEMPLATE);
        if (count($custom_table->getRelationTables(false)) > 0) {
            $this->markTestSkipped('mail_template unexpectedly has relation tables in this environment');
        }

        $content = $this->getDecodedContent(admin_urls('data', SystemTableName::MAIL_TEMPLATE));

        $this->assertStringNotContainsString('search?table_name=' . SystemTableName::MAIL_TEMPLATE . '&value_id=', $content, 'relation search link must not appear when the table has no relation tables');
    }

    // ------------------------------------------------------------------ //
    //  Detail screen (DefaultShow tools)                                 //
    // ------------------------------------------------------------------ //

    /**
     * Active record detail -> relation search button is shown.
     */
    public function testButtonShownOnActiveDetail(): void
    {
        $this->createOrganizationUserRelation();
        $org = $this->createOrganization();

        $content = $this->getDecodedContent(admin_urls('data', SystemTableName::ORGANIZATION, $org->id));

        $this->assertStringContainsString($this->relationSearchUrl($org) . '&relation=1', $content, 'relation search button should be shown on an active record detail');
    }

    /**
     * Soft-deleted record detail (opened with ?trashed=1) -> relation search button is hidden.
     */
    public function testButtonHiddenOnTrashedDetail(): void
    {
        $this->createOrganizationUserRelation();
        $org = $this->createOrganization();
        $org->delete();

        $content = $this->getDecodedContent(admin_urls('data', SystemTableName::ORGANIZATION, $org->id) . '?trashed=1');

        $this->assertStringContainsString($org->getValue('organization_code'), $content, 'trashed organization detail should be shown');
        $this->assertStringNotContainsString($this->relationSearchUrl($org) . '&relation=1', $content, 'relation search button must be hidden on a trashed record detail');
    }

    // ------------------------------------------------------------------ //
    //  Helpers                                                           //
    // ------------------------------------------------------------------ //

    /**
     * Guarantee the organization table has at least one relation table:
     * add a 1:N relation organization -> user. Metadata row only (custom values
     * always have parent_id/parent_type columns), so it rolls back cleanly.
     */
    protected function createOrganizationUserRelation(): CustomRelation
    {
        $relation = CustomRelation::create([
            'parent_custom_table_id' => CustomTable::getEloquent(SystemTableName::ORGANIZATION)->id,
            'child_custom_table_id' => CustomTable::getEloquent(SystemTableName::USER)->id,
            'relation_type' => RelationType::ONE_TO_MANY,
        ]);

        // drop request-session caches (relation table list is cached per table)
        System::clearCache();
        \Exceedone\Exment\Middleware\Morph::defineMorphMap();

        return $relation;
    }

    protected function createOrganization(): CustomValue
    {
        $code = 'trsicon_' . short_uuid();

        $org = CustomTable::getEloquent(SystemTableName::ORGANIZATION)->getValueModel();
        $org->setValue([
            'organization_code' => $code,
            'organization_name' => $code,
        ]);
        $org->saved_notify(false);
        $org->save();

        return $org;
    }

    /**
     * Row-specific relation search url ("&relation=1" is appended on the detail screen button).
     */
    protected function relationSearchUrl(CustomValue $custom_value): string
    {
        return 'search?table_name=' . SystemTableName::ORGANIZATION . "&value_id={$custom_value->id}";
    }

    /**
     * GET the url and return the html-entity-decoded body (hrefs are escaped in html).
     */
    protected function getDecodedContent(string $url): string
    {
        $response = $this->get($url);
        $response->assertStatus(200);

        return html_entity_decode($response->getContent());
    }
}
