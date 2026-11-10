<?php

namespace App\Console\Commands;

use App\Services\CsvImport\BuildDeskImportService;
use Illuminate\Console\Command;
use Throwable;

class ImportBuildDeskCommand extends Command
{
    protected $signature = 'import:builddesk {--path= : Custom directory containing BuildDesk Pro CSV exports}';

    protected $description = 'Idempotently import and cleanse BuildDesk Pro CSV data into MaintenanceFlow';

    public function handle(BuildDeskImportService $importer): int
    {
        $directory = $this->option('path') ?: storage_path('app/private/source-imports');

        $this->info("Starting BuildDesk Pro import from: {$directory}");

        try {
            $stats = $importer->importFromDirectory($directory);
        } catch (Throwable $e) {
            $this->error('Import failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Entity', 'Created', 'Updated', 'Skipped / Synced'],
            [
                ['Customers', $stats['customers']['created'], $stats['customers']['updated'], $stats['customers']['skipped']],
                ['Installations', $stats['installations']['created'], $stats['installations']['updated'], $stats['installations']['skipped']],
                ['Maintenance Plans', $stats['plans']['created'], $stats['plans']['updated'], '-'],
                ['Components', '-', '-', $stats['components']['synced']],
                ['Work Orders', $stats['work_orders']['created'], $stats['work_orders']['updated'], $stats['work_orders']['skipped']],
                ['Work Order Materials', '-', '-', $stats['materials']['synced']],
                ['Service Reports', $stats['reports']['created'], $stats['reports']['updated'], '-'],
            ]
        );

        $warningCount = count($stats['warnings']);
        if ($warningCount > 0) {
            $this->warn("Logged {$warningCount} data-cleansing warnings (missing customer refs or inverted legacy hours).");
        }

        $this->info('BuildDesk Pro import completed successfully.');

        return self::SUCCESS;
    }
}