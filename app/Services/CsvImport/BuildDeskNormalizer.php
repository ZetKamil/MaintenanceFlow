<?php

namespace App\Services\CsvImport;

use Carbon\Carbon;
use Throwable;

class BuildDeskNormalizer
{
    /**
     * Parse Belgian DD/MM/YYYY (or ISO Y-m-d) date safely without guessing ambiguous strings.
     */
    public function parseDate(?string $value): ?Carbon
    {
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        foreach (['!d/m/Y', '!Y-m-d'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $trimmed);
                if ($date !== false) {
                    return $date;
                }
            } catch (Throwable) {
                // Try next format
            }
        }

        return null;
    }

    /**
     * Normalize inconsistent BuildDesk installation groups/types.
     */
    public function normalizeInstallationType(?string $type, ?string $group = null): string
    {
        $candidate = strtoupper(trim($type ?: $group ?: ''));

        return match ($candidate) {
            'BRAND', 'BRANDDET' => 'BRAND',
            'INBR', 'INBRAAK', 'ALARM' => 'INBR',
            'CAM', 'CAMERA' => 'CAM',
            'TOEG', 'TOEGANG' => 'TOEG',
            default => 'OTHER',
        };
    }

    /**
     * Normalize free-text maintenance intervals into months.
     * Statutory rule: BRAND (fire detection) defaults to 12 months when blank/unknown.
     */
    public function normalizeIntervalMonths(?string $rawFrequency, string $installationType): ?int
    {
        $normalized = strtolower(trim((string) $rawFrequency));

        $months = match ($normalized) {
            '1j', '12m', '12', 'jaarlijks', '1 x per jaar' => 12,
            '2j', '24m', '24' => 24,
            '6m', '6' => 6,
            '3m', '3' => 3,
            default => null,
        };

        if ($months === null && $installationType === 'BRAND') {
            return 12;
        }

        return $months;
    }

    /**
     * Parse scheduled start and end times, fixing corrupted rows where end <= start.
     *
     * @return array{start: Carbon, end: Carbon, fixed: bool}|null
     */
    public function parseTimeSlot(?string $dateStr, ?string $startTime, ?string $endTime): ?array
    {
        $date = $this->parseDate($dateStr);
        $startStr = trim((string) $startTime);
        $endStr = trim((string) $endTime);

        if (!$date || $startStr === '') {
            return null;
        }

        try {
            $start = Carbon::createFromFormat('!Y-m-d H:i', $date->format('Y-m-d') . ' ' . $startStr);
        } catch (Throwable) {
            return null;
        }

        $fixed = false;
        $end = null;

        if ($endStr !== '') {
            try {
                $end = Carbon::createFromFormat('!Y-m-d H:i', $date->format('Y-m-d') . ' ' . $endStr);
            } catch (Throwable) {
                $end = null;
            }
        }

        // Clean legacy BuildDesk bug where appointments ended before they started (e.g. 13:00 -> 10:00)
        if (!$end || $end->lte($start)) {
            $end = $start->copy()->addHours(2);
            $fixed = true;
        }

        return [
            'start' => $start,
            'end' => $end,
            'fixed' => $fixed,
        ];
    }

    /**
     * Convert Belgian decimal comma strings (e.g. "1,5") into floats.
     */
    public function parseDecimal(?string $value): float
    {
        $cleaned = str_replace([' ', ','], ['', '.'], trim((string) $value));

        return is_numeric($cleaned) ? (float) $cleaned : 0.0;
    }
}