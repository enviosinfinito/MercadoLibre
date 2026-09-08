<?php

namespace App\Services\Export;

use Generator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

final class XlsxExportWriter
{
    public function __construct(
        private readonly FormulaEvaluator $formulas = new FormulaEvaluator,
    ) {}

    /**
     * @param  list<string>  $orderedKeys  mix of registry keys and formula__* keys
     * @param  array<string, string>  $keyToLabel
     * @param  list<array{key: string, name: string, expression: string}>  $formulaColumns
     * @param  Generator<int, array<string, mixed>>  $rows  rows keyed by registry labels
     * @return array{path: string, row_count: int, file_size: int}
     */
    public function write(
        string $absolutePath,
        array $orderedKeys,
        array $keyToLabel,
        array $formulaColumns,
        Generator $rows,
    ): array {
        $dir = dirname($absolutePath);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Unable to create export directory [{$dir}]");
        }

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Export');

        $formulaMap = [];
        foreach ($formulaColumns as $formula) {
            $formulaMap[$formula['key']] = $formula;
        }

        $headers = [];
        foreach ($orderedKeys as $key) {
            if (str_starts_with($key, 'formula__')) {
                $headers[] = $formulaMap[$key]['name'] ?? $key;
            } else {
                $headers[] = $keyToLabel[$key] ?? $key;
            }
        }

        $col = 1;
        foreach ($headers as $header) {
            $sheet->setCellValue([$col, 1], $header);
            $col++;
        }

        $rowNum = 2;
        $rowCount = 0;
        foreach ($rows as $rowByLabel) {
            $enriched = $rowByLabel;
            foreach ($formulaColumns as $formula) {
                $enriched[$formula['name']] = $this->formulas->evaluate(
                    $formula['expression'],
                    $enriched,
                    $keyToLabel,
                );
            }

            $col = 1;
            foreach ($orderedKeys as $key) {
                if (str_starts_with($key, 'formula__')) {
                    $name = $formulaMap[$key]['name'] ?? $key;
                    $value = $enriched[$name] ?? '-';
                } else {
                    $label = $keyToLabel[$key] ?? $key;
                    $value = $enriched[$label] ?? null;
                }
                $sheet->setCellValue([$col, $rowNum], $value);
                $col++;
            }
            $rowNum++;
            $rowCount++;
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($absolutePath);
        $spreadsheet->disconnectWorksheets();

        return [
            'path' => $absolutePath,
            'row_count' => $rowCount,
            'file_size' => (int) (filesize($absolutePath) ?: 0),
        ];
    }
}
