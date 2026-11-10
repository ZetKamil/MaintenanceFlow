<?php

namespace Database\Seeders;

use App\Services\CsvImport\BuildDeskImportService;
use Illuminate\Database\Seeder;

class BuildDeskSeeder extends Seeder
{
    /**
     * Import and normalize the fictional Verlinde Beveiliging BV dataset.
     */
    public function run(BuildDeskImportService $importer): void
    {
        $directory = database_path('data/builddesk');

        if (!is_dir($directory)) {
            $directory = storage_path('app/private/source-imports');
        }

        if (!is_dir($directory)) {
            $this->command?->warn('No BuildDesk CSV dataset directory found; skipping CSV seed.');

            return;
        }

        $stats = $importer->importFromDirectory($directory);

        $this->command?->info(sprintf(
            'BuildDesk dataset seeded (%d customers, %d installations, %d work orders).',
            $stats['customers']['created'] + $stats['customers']['updated'],
            $stats['installations']['created'] + $stats['installations']['updated'],
            $stats['work_orders']['created'] + $stats['work_orders']['updated'],
        ));
    }
}

