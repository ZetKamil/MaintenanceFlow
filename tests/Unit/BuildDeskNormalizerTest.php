<?php

namespace Tests\Unit;

use App\Services\CsvImport\BuildDeskNormalizer;
use PHPUnit\Framework\TestCase;

class BuildDeskNormalizerTest extends TestCase
{
    private BuildDeskNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new BuildDeskNormalizer();
    }

    public function test_it_parses_belgian_and_iso_dates_and_rejects_invalid_strings(): void
    {
        $belgian = $this->normalizer->parseDate('15/03/2025');
        $iso = $this->normalizer->parseDate('2026-11-10');

        $this->assertNotNull($belgian);
        $this->assertSame('2025-03-15', $belgian->toDateString());

        $this->assertNotNull($iso);
        $this->assertSame('2026-11-10', $iso->toDateString());

        $this->assertNull($this->normalizer->parseDate(''));
        $this->assertNull($this->normalizer->parseDate('onbekend'));
        $this->assertNull($this->normalizer->parseDate(null));
    }

    public function test_it_normalizes_inconsistent_installation_types(): void
    {
        $this->assertSame('BRAND', $this->normalizer->normalizeInstallationType('BRANDDET'));
        $this->assertSame('BRAND', $this->normalizer->normalizeInstallationType(null, 'Brand'));
        $this->assertSame('INBR', $this->normalizer->normalizeInstallationType('ALARM'));
        $this->assertSame('CAM', $this->normalizer->normalizeInstallationType('camera'));
        $this->assertSame('TOEG', $this->normalizer->normalizeInstallationType('toegang'));
        $this->assertSame('OTHER', $this->normalizer->normalizeInstallationType('UNKNOWN'));
    }

    public function test_it_normalizes_frequency_labels_and_enforces_statutory_brand_interval(): void
    {
        $this->assertSame(12, $this->normalizer->normalizeIntervalMonths('1J', 'INBR'));
        $this->assertSame(12, $this->normalizer->normalizeIntervalMonths('12M', 'INBR'));
        $this->assertSame(12, $this->normalizer->normalizeIntervalMonths('jaarlijks', 'INBR'));
        $this->assertSame(12, $this->normalizer->normalizeIntervalMonths('1 x per jaar', 'INBR'));
        $this->assertSame(12, $this->normalizer->normalizeIntervalMonths('12', 'INBR'));
        $this->assertSame(24, $this->normalizer->normalizeIntervalMonths('2J', 'CAM'));
        $this->assertSame(6, $this->normalizer->normalizeIntervalMonths('6M', 'TOEG'));

        // Non-fire installations stay null when interval is blank or on-request
        $this->assertNull($this->normalizer->normalizeIntervalMonths('op vraag', 'INBR'));
        $this->assertNull($this->normalizer->normalizeIntervalMonths('?', 'CAM'));

        // Fire detection (BRAND) legally requires annual 12-month cycle even when CSV is blank/?
        $this->assertSame(12, $this->normalizer->normalizeIntervalMonths('', 'BRAND'));
        $this->assertSame(12, $this->normalizer->normalizeIntervalMonths('?', 'BRAND'));
    }

    public function test_it_repairs_inverted_or_zero_duration_work_order_hours(): void
    {
        $valid = $this->normalizer->parseTimeSlot('10/11/2026', '08:30', '11:30');
        $this->assertNotNull($valid);
        $this->assertFalse($valid['fixed']);
        $this->assertSame('2026-11-10 08:30', $valid['start']->format('Y-m-d H:i'));
        $this->assertSame('2026-11-10 11:30', $valid['end']->format('Y-m-d H:i'));

        $inverted = $this->normalizer->parseTimeSlot('10/11/2026', '13:00', '10:00');
        $this->assertNotNull($inverted);
        $this->assertTrue($inverted['fixed']);
        $this->assertSame('2026-11-10 13:00', $inverted['start']->format('Y-m-d H:i'));
        $this->assertSame('2026-11-10 15:00', $inverted['end']->format('Y-m-d H:i'));

        $sameTime = $this->normalizer->parseTimeSlot('10/11/2026', '10:00', '10:00');
        $this->assertNotNull($sameTime);
        $this->assertTrue($sameTime['fixed']);
        $this->assertSame('2026-11-10 12:00', $sameTime['end']->format('Y-m-d H:i'));
    }

    public function test_it_normalizes_belgian_decimals_phones_and_whitespace(): void
    {
        $this->assertSame(1.5, $this->normalizer->parseDecimal('1,5'));
        $this->assertSame(2.0, $this->normalizer->parseDecimal(' 2 '));
        $this->assertSame(0.0, $this->normalizer->parseDecimal('abc'));

        // All 6 real BuildDesk phone formats convert cleanly to canonical E.164
        $this->assertSame('+32400004871', $this->normalizer->normalizePhone('0400/00.48.71'));
        $this->assertSame('+32400006144', $this->normalizer->normalizePhone('0400 00 61 44'));
        $this->assertSame('+32400001988', $this->normalizer->normalizePhone('+32400001988'));
        $this->assertSame('+3256003983', $this->normalizer->normalizePhone('+32 56 003983'));
        $this->assertSame('+3256008848', $this->normalizer->normalizePhone('056/00.88.48'));
        $this->assertSame('+3256004526', $this->normalizer->normalizePhone('056 00 45 26'));
        $this->assertSame('+3256004526', $this->normalizer->normalizePhone('0032 (0)56 00 45 26'));
        $this->assertNull($this->normalizer->normalizePhone('nvt'));

        $this->assertSame('Metaalbouw Verschuere', $this->normalizer->normalizeText('  Metaalbouw   Verschuere  '));
        $this->assertNull($this->normalizer->normalizeText('   '));
    }
}

