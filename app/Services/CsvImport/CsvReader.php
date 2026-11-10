<?php

namespace App\Services\CsvImport;

use Generator;
use RuntimeException;

class CsvReader
{
    /**
     * Stream a CSV file row by row as associative arrays [header => value].
     * Automatically strips UTF-8 BOM and detects semicolon/comma delimiters.
     *
     * @return Generator<int, array<string, string>>
     */
    public function read(string $filePath): Generator
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new RuntimeException("CSV file not found or not readable: {$filePath}");
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Unable to open CSV file: {$filePath}");
        }

        try {
            $firstLine = fgets($handle);
            if ($firstLine === false) {
                return;
            }

            // Strip invisible UTF-8 BOM often added by Excel / Windows exports
            $firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine);

            // Detect delimiter: Belgian BuildDesk exports use ';', fallback to ','
            $delimiter = str_contains($firstLine, ';') ? ';' : ',';

            // Explicit $escape parameter prevents deprecation warnings in PHP 8.4+
            $headers = str_getcsv(trim($firstLine, "\r\n"), $delimiter, '"', '\\');
            $headers = array_map(fn(?string $h) => trim((string) $h), $headers);

            while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
                // Skip completely empty lines
                if ($row === [null] || count(array_filter($row, fn($v) => $v !== null && $v !== '')) === 0) {
                    continue;
                }

                $normalized = [];
                foreach ($headers as $index => $header) {
                    if ($header === '') {
                        continue;
                    }
                    $normalized[$header] = isset($row[$index]) ? trim((string) $row[$index]) : '';
                }

                if ($normalized !== []) {
                    yield $normalized;
                }
            }
        } finally {
            fclose($handle);
        }
    }
}