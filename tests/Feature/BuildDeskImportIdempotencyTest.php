<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Models\Customer;
use App\Models\Installation;
use App\Models\MaintenancePlan;
use App\Models\WorkOrder;
use App\Services\CsvImport\BuildDeskImportService;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildDeskImportIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserSeeder::class);
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mf_import_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    public function test_csv_import_cleans_anomalies_propagates_inactive_customers_and_is_idempotent(): void
    {
        $this->writeCsv('BD_EXP_REL_TEST.csv', [
            ['code', 'naam', 'type', 'straat', 'nr', 'postcode', 'gemeente', 'tel', 'gsm', 'email', 'actief'],
            ['K001', 'Metaalbouw  Verschuere', 'Bedrijf', 'Kortrijkstraat', '10', '8550', 'Zwevegem', '056/00.45.26', '0400/00.48.71', 'INFO@VERSCHUERE.BE', 'J'],
            ['K002', 'Inactive Bakery', 'Bedrijf', 'Dorpsstraat', '4', '8550', 'Zwevegem', '', '0400 00 11 22', '', 'N'],
            ['L001', 'SecuDistri Supplier', 'Leverancier', 'Indlaan', '1', '8500', 'Kortrijk', '', '', '', 'J'],
        ]);

        $this->writeCsv('BD_EXP_MS_HDR_TEST.csv', [
            ['nr', 'groep', 'type', 'omschrijving', 'klant', 'frequentie', 'laatste', 'volgende', 'actief', 'pand', 'notities'],
            ['1001', 'BRANDDET', 'BRAND', 'Brandcentrale  Hal 1', 'K001', '?', '15/01/2025', '01/01/2024', 'J', 'Kortrijkstraat 10, 8550 Zwevegem', 'Check  batteries'],
            ['1002', 'INBR', 'INBR', 'Alarm Bakery', 'K002', '1J', '10/02/2025', '', 'J', 'Dorpsstraat 4, 8550 Zwevegem', ''],
            ['1003', 'INBR', 'INBR', 'Orphan Site Missing Customer', '', '1J', '01/01/2025', '', 'J', 'Kerkstraat 1, 8550 Zwevegem', ''],
        ]);

        $this->writeCsv('BD_EXP_WO_HDR_TEST.csv', [
            ['nr', 'datum', 'status', 'klant', 'omschrijving', 'fiche', 'soort', 'notaTech', 'notaKantoor'],
            ['WB-001', '10/11/2026', 'Planned', 'K001', 'Jaarlijks onderhoud', '1001', 'Onderhoud', '', ''],
            ['WB-002', '01/03/2025', 'Completed', 'K001', 'Vorige controle', '1001', 'Onderhoud', '', 'Alles OK'],
        ]);

        $this->writeCsv('BD_EXP_WO_HRS_PL_TEST.csv', [
            ['werkbon', 'datum', 'tech', 'van', 'tot'],
            ['WB-001', '10/11/2026', 'TDC', '13:00', '10:00'],
        ]);

        $this->writeCsv('BD_EXP_WO_HRS_EX_TEST.csv', [
            ['werkbon', 'datum', 'tech', 'van', 'tot'],
            ['WB-002', '01/03/2025', 'TDC', '09:00', '11:00'],
        ]);

        $service = app(BuildDeskImportService::class);

        // First import run
        $firstStats = $service->importFromDirectory($this->tempDir);

        $this->assertSame(2, $firstStats['customers']['created']);
        $this->assertSame(1, $firstStats['customers']['skipped']); // Leverancier skipped
        $this->assertSame(2, $firstStats['installations']['created']);
        $this->assertSame(1, $firstStats['installations']['skipped']); // Orphan skipped
        $this->assertSame(2, $firstStats['work_orders']['created']);
        $this->assertSame(1, $firstStats['reports']['created']);

        // Verify data cleansing & inactive customer propagation
        $activeCustomer = Customer::where('external_id', 'K001')->firstOrFail();
        $this->assertSame('Metaalbouw Verschuere', $activeCustomer->name);
        $this->assertSame('+32400004871', $activeCustomer->phone);
        $this->assertSame('info@verschuere.be', $activeCustomer->email);

        $brandPlan = MaintenancePlan::where('external_id', '1001')->firstOrFail();
        $this->assertSame(12, $brandPlan->interval_months); // Statutory BRAND fallback
        $this->assertSame('2026-01-15', $brandPlan->next_due_at->toDateString()); // Repaired inverted volgende < laatste
        $this->assertTrue($brandPlan->is_active);

        $inactivePlan = MaintenancePlan::where('external_id', '1002')->firstOrFail();
        $this->assertFalse($inactivePlan->is_active); // Propagated from inactive customer K002

        $scheduledOrder = WorkOrder::where('external_id', 'WB-001')->firstOrFail();
        $this->assertSame(WorkOrderStatus::SCHEDULED, $scheduledOrder->status);
        $this->assertSame('2026-11-10 13:00', $scheduledOrder->scheduled_start_at->format('Y-m-d H:i'));
        $this->assertSame('2026-11-10 15:00', $scheduledOrder->scheduled_end_at->format('Y-m-d H:i')); // Repaired inverted hours

        // Second import run must create zero duplicates (idempotency)
        $secondStats = $service->importFromDirectory($this->tempDir);

        $this->assertSame(0, $secondStats['customers']['created']);
        $this->assertSame(0, $secondStats['installations']['created']);
        $this->assertSame(0, $secondStats['plans']['created']);
        $this->assertSame(0, $secondStats['work_orders']['created']);
        $this->assertSame(0, $secondStats['reports']['created']);

        $this->assertDatabaseCount('customers', 2);
        $this->assertDatabaseCount('installations', 2);
        $this->assertDatabaseCount('work_orders', 2);
        $this->assertDatabaseCount('maintenance_reports', 1);
    }

    private function writeCsv(string $filename, array $rows): void
    {
        $handle = fopen($this->tempDir . DIRECTORY_SEPARATOR . $filename, 'wb');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ';', '"', '\\');
        }
        fclose($handle);
    }
}

