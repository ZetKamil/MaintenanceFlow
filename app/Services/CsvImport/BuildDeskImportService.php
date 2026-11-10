<?php

namespace App\Services\CsvImport;

use App\Enums\WorkOrderStatus;
use App\Models\WorkOrderMaterial;
use App\Models\Customer;
use App\Models\Installation;
use App\Models\InstallationComponent;
use App\Models\MaintenancePlan;
use App\Models\MaintenanceReport;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use RuntimeException;


class BuildDeskImportService
{
    private const SOURCE_SYSTEM = 'builddesk_pro';

    public function __construct(
        private readonly CsvReader $reader,
        private readonly BuildDeskNormalizer $normalizer,
    ) {
    }

    public function importFromDirectory(string $directory): array
    {
        if (!is_dir($directory)) {
            throw new RuntimeException("Import directory does not exist: {$directory}");
        }

        $stats = [
            'customers' => ['created' => 0, 'updated' => 0, 'skipped' => 0],
            'installations' => ['created' => 0, 'updated' => 0, 'skipped' => 0],
            'plans' => ['created' => 0, 'updated' => 0],
            'components' => ['synced' => 0],
            'work_orders' => ['created' => 0, 'updated' => 0, 'skipped' => 0],
            'materials' => ['synced' => 0],
            'reports' => ['created' => 0, 'updated' => 0],
            'warnings' => [],
        ];

        DB::transaction(function () use ($directory, &$stats) {
            $teamMap = $this->resolveTeamMap();
            $customerMap = $this->importCustomers($directory, $stats);
            [$installationMap, $planMap, $customerFirstInstMap] = $this->importInstallationsAndPlans($directory, $customerMap, $stats);
            $this->importComponents($directory, $installationMap, $stats);
            $workOrderMap = $this->importWorkOrders($directory, $customerMap, $installationMap, $planMap, $customerFirstInstMap, $teamMap, $stats);
            $this->importWorkOrderMaterials($directory, $workOrderMap, $stats);
            $this->reconcileMissingPlanDates($stats);
        });

        return $stats;
    }

    private function resolveTeamMap(): array
    {
        $emailByCode = [
            'DV' => 'dirk@verlinde.be',
            'TDC' => 'tom@verlinde.be',
            'WVH' => 'wout@verlinde.be',
            'JMS' => 'jens@verlinde.be',
            'BRT' => 'contact@brandtec.test',
        ];
        $users = User::whereIn('email', array_values($emailByCode))->pluck('id', 'email');
        $map = [];
        foreach ($emailByCode as $code => $email) {
            if (isset($users[$email])) {
                $map[$code] = (int) $users[$email];
            }
        }
        return $map;
    }

    private function importCustomers(string $directory, array &$stats): array
    {
        $file = $this->findCsvFile($directory, 'BD_EXP_REL_')
            ?? throw new RuntimeException('Missing customer export (BD_EXP_REL_*.csv).');
        $map = [];

        foreach ($this->reader->read($file) as $row) {
            $code = $row['code'] ?? '';
            if ($code === '' || strtolower($row['type'] ?? '') === 'leverancier') {
                $stats['customers']['skipped']++;
                continue;
            }
            $street = $this->normalizer->normalizeText(($row['straat'] ?? '') . ' ' . ($row['nr'] ?? ''));
            $phone = $this->normalizer->normalizePhone($row['gsm'] ?? null)
                ?? $this->normalizer->normalizePhone($row['tel'] ?? null);
            $email = $this->normalizer->normalizeText($row['email'] ?? null);

            $customer = Customer::updateOrCreate(
                ['source_system' => self::SOURCE_SYSTEM, 'external_id' => $code],
                [
                    'name' => $this->normalizer->normalizeText($row['naam'] ?? null) ?? "Customer {$code}",
                    'email' => $email ? strtolower($email) : null,
                    'phone' => $phone,
                    'street' => $street,
                    'postal_code' => $this->normalizer->normalizeText($row['postcode'] ?? null),
                    'city' => $this->normalizer->normalizeText($row['gemeente'] ?? null),
                    'is_active' => strtoupper(trim($row['actief'] ?? 'J')) === 'J',
                ]
            );
            $customer->wasRecentlyCreated ? $stats['customers']['created']++ : $stats['customers']['updated']++;
            $map[$code] = $customer;
        }
        return $map;
    }

    private function importInstallationsAndPlans(string $directory, array $customerMap, array &$stats): array
    {
        $file = $this->findCsvFile($directory, 'BD_EXP_MS_HDR_')
            ?? throw new RuntimeException('Missing installation export (BD_EXP_MS_HDR_*.csv).');
        $installationMap = $planMap = $customerFirstInstMap = [];

        foreach ($this->reader->read($file) as $row) {
            $nr = $row['nr'] ?? '';
            $customerCode = $row['klant'] ?? '';
            $customer = $customerMap[$customerCode] ?? null;

            if ($nr === '' || !$customer) {
                $stats['installations']['skipped']++;
                $stats['warnings'][] = "Skipped installation #{$nr}: missing customer '{$customerCode}'.";
                continue;
            }

            $type = $this->normalizer->normalizeInstallationType($row['type'] ?? null, $row['groep'] ?? null);
            [$street, $postal, $city] = $this->parseLocation($row['pand'] ?? '', $customer);

            $installation = Installation::updateOrCreate(
                ['source_system' => self::SOURCE_SYSTEM, 'external_id' => $nr],
                [
                    'customer_id' => $customer->id,
                    'name' => $this->normalizer->normalizeText($row['omschrijving'] ?? null) ?? "Installation #{$nr}",
                    'installation_type' => $type,
                    'location_street' => $this->normalizer->normalizeText($street),
                    'location_postal_code' => $this->normalizer->normalizeText($postal),
                    'location_city' => $this->normalizer->normalizeText($city),
                    'is_active' => $customer->is_active && strtoupper(trim($row['actief'] ?? 'J')) === 'J',
                ]
            );
            $installation->wasRecentlyCreated ? $stats['installations']['created']++ : $stats['installations']['updated']++;
            $installationMap[$nr] = $installation;
            $customerFirstInstMap[$customer->id] ??= $installation;

            $interval = $this->normalizer->normalizeIntervalMonths($row['frequentie'] ?? null, $type);
            $last = $this->normalizer->parseDate($row['laatste'] ?? null);
            $next = $this->normalizer->parseDate($row['volgende'] ?? null);

            if ($last && $next && $next->lt($last)) {
                $next = null;
            }
            if (!$next && $last && $interval) {
                $next = $last->copy()->addMonths($interval);
            }

            $plan = MaintenancePlan::updateOrCreate(
                ['source_system' => self::SOURCE_SYSTEM, 'external_id' => $nr],
                [
                    'installation_id' => $installation->id,
                    'maintenance_type' => $type,
                    'interval_months' => $interval ?? 0,
                    'last_completed_at' => $last?->toDateString(),
                    'next_due_at' => $next?->toDateString(),
                    'is_active' => $installation->is_active,
                    'source_notes' => $this->normalizer->normalizeText($row['notities'] ?? null),
                ]
            );
            $plan->wasRecentlyCreated ? $stats['plans']['created']++ : $stats['plans']['updated']++;
            $planMap[$nr] = $plan;
        }

        return [$installationMap, $planMap, $customerFirstInstMap];
    }

    private function importComponents(string $directory, array $installationMap, array &$stats): void
    {
        if (!$file = $this->findCsvFile($directory, 'BD_EXP_MS_COMP_')) {
            return;
        }
        $grouped = [];
        foreach ($this->reader->read($file) as $row) {
            $fiche = $row['fiche'] ?? '';
            if (isset($installationMap[$fiche])) {
                $grouped[$fiche][] = $row;
            }
        }
        foreach ($grouped as $fiche => $rows) {
            $installation = $installationMap[$fiche];
            $installation->components()->delete();
            $now = now();
            $records = array_map(fn(array $row) => [
                'installation_id' => $installation->id,
                'article_code' => ($row['artikel'] ?? '') !== '' ? $row['artikel'] : null,
                'description' => ($row['omschrijving'] ?? '') !== '' ? $row['omschrijving'] : 'Component',
                'quantity' => max(1, (int) $this->normalizer->parseDecimal($row['aantal'] ?? '1')),
                'serial_number' => ($row['serienr'] ?? '') !== '' ? $row['serienr'] : null,
                'location' => ($row['locatie'] ?? '') !== '' ? $row['locatie'] : null,
                'created_at' => $now,
                'updated_at' => $now,
            ], $rows);
            InstallationComponent::insert($records);
            $stats['components']['synced'] += count($records);
        }
    }

    private function importWorkOrders(string $directory, array $customerMap, array $installationMap, array $planMap, array $customerFirstInstMap, array $teamMap, array &$stats): array
    {
        $workOrderMap = [];
        if (!$woFile = $this->findCsvFile($directory, 'BD_EXP_WO_HDR_')) {
            return [];
        }
        $woObjMap = [];
        if ($objFile = $this->findCsvFile($directory, 'BD_EXP_WO_OBJ_')) {
            foreach ($this->reader->read($objFile) as $row) {
                if (($row['werkbon'] ?? '') !== '' && ($row['fiche'] ?? '') !== '') {
                    $woObjMap[$row['werkbon']] = $row['fiche'];
                }
            }
        }
        $hrsPlMap = $this->loadHoursMap($this->findCsvFile($directory, 'BD_EXP_WO_HRS_PL_'));
        $hrsExMap = $this->loadHoursMap($this->findCsvFile($directory, 'BD_EXP_WO_HRS_EX_'));

        foreach ($this->reader->read($woFile) as $row) {
            $nr = $row['nr'] ?? '';
            if ($nr === '') {
                continue;
            }
            $fiche = ($row['fiche'] ?? '') !== '' ? $row['fiche'] : ($woObjMap[$nr] ?? '');
            $installation = $installationMap[$fiche] ?? ($customerFirstInstMap[$customerMap[$row['klant'] ?? '']->id ?? 0] ?? null);
            if (!$installation) {
                $stats['work_orders']['skipped']++;
                $stats['warnings'][] = "Skipped work order {$nr}: missing valid installation or customer.";
                continue;
            }

            $isMaintenance = strtolower($row['soort'] ?? '') === 'onderhoud'
                || str_contains(strtolower($row['omschrijving'] ?? ''), 'onderhoud')
                || str_contains(strtolower($row['omschrijving'] ?? ''), 'ondh');
            $plan = $isMaintenance ? ($planMap[$installation->external_id] ?? null) : null;
            $woDate = $this->normalizer->parseDate($row['datum'] ?? null);
            $plHour = $hrsPlMap[$nr] ?? null;
            $exHour = $hrsExMap[$nr] ?? null;
            $slot = $plHour ? $this->normalizer->parseTimeSlot($plHour['datum'], $plHour['van'], $plHour['tot']) : null;
            if ($slot && $slot['fixed']) {
                $stats['warnings'][] = "Fixed inverted hours on work order {$nr}.";
            }

            $rawStatus = strtolower($row['status'] ?? '');
            $officeNote = strtolower($row['notaKantoor'] ?? '');
            $status = match (true) {
                in_array($rawStatus, ['completed', 'invoiced', 'verified', 'implemented'], true) => WorkOrderStatus::COMPLETED,
                $rawStatus === 'cancelled' => WorkOrderStatus::CANCELLED,
                $slot !== null => WorkOrderStatus::SCHEDULED,
                str_contains($officeNote, 'wacht') || str_contains($officeNote, 'reis') => WorkOrderStatus::AWAITING_CUSTOMER,
                default => WorkOrderStatus::NEEDS_CONTACT,
            };

            if ($status === WorkOrderStatus::SCHEDULED && !$installation->is_active) {
                $stats['warnings'][] = "Scheduled work order {$nr} belongs to inactive installation/customer.";
            }

            $techCode = $exHour['tech'] ?? ($plHour['tech'] ?? null);
            $techId = $techCode ? ($teamMap[$techCode] ?? null) : null;
            $notes = implode("\n", array_filter([
                $row['omschrijving'] ?? '',
                ($row['notaTech'] ?? '') !== '' ? 'Tech note: ' . $row['notaTech'] : '',
                ($row['notaKantoor'] ?? '') !== '' ? 'Office note: ' . $row['notaKantoor'] : '',
            ]));

            $wo = WorkOrder::updateOrCreate(
                ['source_system' => self::SOURCE_SYSTEM, 'external_id' => $nr],
                [
                    'installation_id' => $installation->id,
                    'maintenance_plan_id' => $plan?->id,
                    'assigned_technician_id' => $techId,
                    'created_by_id' => $teamMap['DV'] ?? null,
                    'cycle_due_date' => $isMaintenance ? $woDate?->toDateString() : null,
                    'status' => $status,
                    'scheduled_start_at' => $slot ? $slot['start'] : null,
                    'scheduled_end_at' => $slot ? $slot['end'] : null,
                    'notes' => $notes !== '' ? $notes : null,
                ]
            );
            $wo->wasRecentlyCreated ? $stats['work_orders']['created']++ : $stats['work_orders']['updated']++;
            $workOrderMap[$nr] = $wo;

            if ($status === WorkOrderStatus::COMPLETED && $exHour) {
                $performedAt = $this->normalizer->parseDate($exHour['datum']) ?? $woDate ?? now();
                $report = MaintenanceReport::updateOrCreate(
                    ['work_order_id' => $wo->id],
                    [
                        'submitted_by_id' => $techId,
                        'performed_at' => $performedAt,
                        'performed_work' => ($row['omschrijving'] ?? '') ?: 'Maintenance service completed.',
                        'findings' => ($row['notaKantoor'] ?? '') !== '' ? $row['notaKantoor'] : null,
                        'next_due_at' => ($plan && $plan->interval_months > 0) ? $performedAt->copy()->addMonths($plan->interval_months)->toDateString() : null,
                    ]
                );
                $report->wasRecentlyCreated ? $stats['reports']['created']++ : $stats['reports']['updated']++;
            }
        }

        return $workOrderMap;
    }

    private function importWorkOrderMaterials(string $directory, array $workOrderMap, array &$stats): void
    {
        if (!$file = $this->findCsvFile($directory, 'BD_EXP_WO_ART_PL_')) {
            return;
        }

        $now = now();
        $orderIds = [];
        $records = [];

        foreach ($this->reader->read($file) as $row) {
            $werkbon = $row['werkbon'] ?? '';
            $wo = $workOrderMap[$werkbon] ?? null;
            if (!$wo) {
                continue;
            }

            $orderIds[$wo->id] = $wo->id;
            $records[] = [
                'work_order_id' => $wo->id,
                'material_code' => ($row['artikel'] ?? '') !== '' ? $row['artikel'] : null,
                'description' => ($row['omschrijving'] ?? '') !== '' ? $row['omschrijving'] : 'Material',
                'quantity' => max(0, $this->normalizer->parseDecimal($row['aantal'] ?? '1')),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Batch delete and chunked insert keep re-imports idempotent without exceeding SQLite bind limits
        foreach (array_chunk(array_values($orderIds), 400) as $idChunk) {
            WorkOrderMaterial::whereIn('work_order_id', $idChunk)->delete();
        }

        foreach (array_chunk($records, 100) as $chunk) {
            WorkOrderMaterial::insert($chunk);
        }

        $stats['materials']['synced'] = count($records);
    }

    private function reconcileMissingPlanDates(array &$stats): void
    {
        foreach (MaintenancePlan::whereNull('last_completed_at')->where('interval_months', '>', 0)->get() as $plan) {
            $latest = WorkOrder::where('maintenance_plan_id', $plan->id)
                ->where('status', WorkOrderStatus::COMPLETED->value)
                ->whereNotNull('cycle_due_date')
                ->orderByDesc('cycle_due_date')
                ->first();

            if ($latest && $latest->cycle_due_date) {
                $plan->update([
                    'last_completed_at' => $latest->cycle_due_date->toDateString(),
                    'next_due_at' => $plan->next_due_at ?? $latest->cycle_due_date->copy()->addMonths($plan->interval_months)->toDateString(),
                ]);
                $stats['plans']['updated']++;
            }
        }
    }

    private function loadHoursMap(?string $file): array
    {
        if (!$file) {
            return [];
        }
        $map = [];
        foreach ($this->reader->read($file) as $row) {
            if (($row['werkbon'] ?? '') !== '') {
                $map[$row['werkbon']] = $row;
            }
        }
        return $map;
    }

    private function parseLocation(string $rawPand, Customer $fallback): array
    {
        $trimmed = $this->normalizer->normalizeText($rawPand) ?? '';
        if ($trimmed === '') {
            return [$fallback->street, $fallback->postal_code, $fallback->city];
        }
        if (preg_match('/^(.+?),\s*(\d{4})\s+(.+)$/', $trimmed, $m)) {
            return [
                $this->normalizer->normalizeText($m[1]),
                trim($m[2]),
                $this->normalizer->normalizeText($m[3]),
            ];
        }
        return [$trimmed, $fallback->postal_code, $fallback->city];
    }

    private function findCsvFile(string $directory, string $prefix): ?string
    {
        $matches = glob(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $prefix . '*.csv');
        return $matches && isset($matches[0]) ? $matches[0] : null;
    }
}