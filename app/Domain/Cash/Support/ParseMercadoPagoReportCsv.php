<?php

namespace App\Domain\Cash\Support;

/**
 * @phpstan-type ReportRow array<string, string|null>
 */
final class ParseMercadoPagoReportCsv
{
    /**
     * @return list<ReportRow>
     */
    public function execute(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $lines = preg_split("/\r\n|\n|\r/", trim($csv)) ?: [];
        if ($lines === [] || trim((string) $lines[0]) === '') {
            return [];
        }

        $delimiter = str_contains((string) $lines[0], ';') ? ';' : ',';
        $headers = str_getcsv((string) array_shift($lines), $delimiter);
        $headers = array_map(fn ($h) => strtoupper(trim((string) $h)), $headers);

        $rows = [];
        foreach ($lines as $line) {
            if (trim((string) $line) === '') {
                continue;
            }
            $cols = str_getcsv((string) $line, $delimiter);
            $row = [];
            foreach ($headers as $i => $header) {
                $row[$header] = isset($cols[$i]) ? trim((string) $cols[$i]) : null;
            }
            $rows[] = $row;
        }

        return $rows;
    }
}
