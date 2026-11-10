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

    /**
     * Normalize inconsistent Belgian phone strings (e.g. "0400/00.48.71", "+32 56 003983", "056 00 45 26")
     * into canonical E.164 format ("+32400004871", "+3256003983").
     */
    public function normalizePhone(?string $value): ?string
    {
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        $cleaned = preg_replace('/[^\d+]/', '', $trimmed) ?? '';
        $hasPlus = str_starts_with($cleaned, '+');
        $digits = str_replace('+', '', $cleaned);

        if ($digits === '') {
            return null;
        }

        // Convert "0032..." -> "+32..."
        if (!$hasPlus && str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
            $hasPlus = true;
        }

        // Fix accidental "+320..." -> "+32..."
        if ($hasPlus && str_starts_with($digits, '320')) {
            $digits = '32' . substr($digits, 3);
        }

        // Convert local Belgian leading "0..." -> "+32..."
        if (!$hasPlus && str_starts_with($digits, '0')) {
            $digits = '32' . substr($digits, 1);
            $hasPlus = true;
        }

        if (strlen($digits) < 8) {
            return null;
        }

        return ($hasPlus ? '+' : '') . $digits;
    }

    /**
     * Trim leading/trailing whitespace and collapse repeated internal spaces.
     */
    public function normalizeText(?string $value): ?string
    {
        $collapsed = preg_replace('/\s+/', ' ', trim((string) $value));

        return ($collapsed !== null && $collapsed !== '') ? $collapsed : null;
    }
}