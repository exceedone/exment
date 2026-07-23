<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Services\TemplateImportExport\TemplateImporter;
use Exceedone\Exment\Enums\DashboardBoxSystemPage;
use Exceedone\Exment\Enums\DashboardType;
use Exceedone\Exment\Enums\DashboardBoxType;

/**
 * Tests for dashboard template export and import, focusing on the "QR/JAN Barcode"
 * system page type (DashboardBoxSystemPage::BARCODE).
 */
class DashboardBoxTemplateTest extends UnitTestBase
{
    use DatabaseTransactions;

    /**
     * Export of a dashboard box whose system page is "barcode" must preserve the
     * target_system_name in the exported JSON so that import can restore it.
     *
     * Regression test for the bug where DashboardBoxSystemPage had no BARCODE
     * class constant, causing getEnum(6) to return null during export, which
     * silently dropped both target_system_id and target_system_name from the
     * exported JSON. On import the configuration then appeared empty.
     *
     * How to reproduce in production:
     *  1. Open a dashboard and add a widget with type "System".
     *  2. Choose "QR/JAN Barcode" as the display item.
     *  3. Save the dashboard.
     *  4. Go to Template > Export, tick "Dashboard" and export a zip.
     *  5. Import that zip on another (or the same, after wiping the dashboard)
     *     Exment environment.
     *  6. Without the fix the imported box shows no system page – the barcode
     *     scanner widget is gone.
     *
     * @return void
     */
    public function testDashboardBoxBarcodeExportPreservesName(): void
    {
        $barcodeId = DashboardBoxSystemPage::options()['barcode']['id'];  // expected: 6

        // Create a system dashboard with one barcode system box
        $dashboard = Dashboard::create([
            'dashboard_name'      => 'test_barcode_export_' . uniqid(),
            'dashboard_view_name' => 'Barcode Export Test',
            'dashboard_type'      => DashboardType::SYSTEM,
            'options'             => ['row1' => 1, 'row2' => 0, 'row3' => 0, 'row4' => 0],
        ]);

        $box = DashboardBox::create([
            'dashboard_id'             => $dashboard->id,
            'row_no'                   => 1,
            'column_no'                => 1,
            'dashboard_box_view_name'  => 'Barcode',
            'dashboard_box_type'       => DashboardBoxType::SYSTEM,
            'options'                  => ['target_system_id' => $barcodeId],
        ]);

        // --- Export ---
        $exportedDashboard = $dashboard->getTemplateExportItems();

        $exportedBoxes = array_get($exportedDashboard, 'dashboard_boxes', []);
        $this->assertCount(1, $exportedBoxes, 'Exported dashboard should have one box');

        $exportedBox = $exportedBoxes[0];

        // The export must have converted target_system_id → target_system_name
        $this->assertArrayNotHasKey(
            'target_system_id',
            array_get($exportedBox, 'options', []),
            'target_system_id should not appear in exported JSON (should be replaced by name)'
        );
        $this->assertEquals(
            'barcode',
            array_get($exportedBox, 'options.target_system_name'),
            'target_system_name must be "barcode" in exported JSON'
        );
    }

    /**
     * A full export→import round-trip for a barcode system box must restore
     * target_system_id correctly in the imported environment.
     *
     * @return void
     */
    public function testDashboardBoxBarcodeRoundTrip(): void
    {
        $barcodeId = DashboardBoxSystemPage::options()['barcode']['id'];  // expected: 6

        $dashboardName = 'test_barcode_rt_' . uniqid();

        // Create source dashboard + box
        $dashboard = Dashboard::create([
            'dashboard_name'      => $dashboardName,
            'dashboard_view_name' => 'Barcode RT Test',
            'dashboard_type'      => DashboardType::SYSTEM,
            'options'             => ['row1' => 1, 'row2' => 0, 'row3' => 0, 'row4' => 0],
        ]);

        DashboardBox::create([
            'dashboard_id'             => $dashboard->id,
            'row_no'                   => 1,
            'column_no'                => 1,
            'dashboard_box_view_name'  => 'Barcode',
            'dashboard_box_type'       => DashboardBoxType::SYSTEM,
            'options'                  => ['target_system_id' => $barcodeId],
        ]);

        // Build template payload (same shape as TemplateExporter::setTemplateDashboard)
        $templatePayload = [
            'dashboards' => [$dashboard->getTemplateExportItems()],
        ];

        // Verify the export contains target_system_name before deleting the dashboard
        $exportedBox = array_get($templatePayload, 'dashboards.0.dashboard_boxes.0', []);
        $this->assertEquals(
            'barcode',
            array_get($exportedBox, 'options.target_system_name'),
            'Exported JSON must carry target_system_name = "barcode"'
        );

        // Simulate "another environment": delete the source records
        $dashboard->delete();

        // --- Import ---
        $importer = new TemplateImporter();
        $importer->import($templatePayload);

        // Find the newly imported dashboard
        $importedDashboard = Dashboard::where('dashboard_name', $dashboardName)->first();
        $this->assertNotNull($importedDashboard, 'Dashboard should be created after import');

        $importedBox = DashboardBox::where('dashboard_id', $importedDashboard->id)
            ->where('dashboard_box_type', DashboardBoxType::SYSTEM)
            ->first();

        $this->assertNotNull($importedBox, 'Dashboard box should be created after import');

        $importedSystemId = array_get($importedBox->options, 'target_system_id');
        $this->assertNotNull(
            $importedSystemId,
            'target_system_id must not be null after import (barcode box config was lost)'
        );
        $this->assertEquals(
            $barcodeId,
            $importedSystemId,
            'target_system_id after import must match the barcode system page id'
        );
    }
}
