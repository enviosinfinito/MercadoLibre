<?php

namespace App\Services\Export;

use App\Models\ExportRun;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

final class ExportPreviewService
{
    /**
     * @return array{
     *   headers: list<string>,
     *   rows: list<list<mixed>>,
     *   offset: int,
     *   limit: int,
     *   total_rows: int,
     *   has_more: bool
     * }
     */
    public function preview(ExportRun $run, string $mode = 'page', int $offset = 0, ?int $limit = null): array
    {
        if ($run->status !== 'completed' || ! $run->storage_path) {
            throw new RuntimeException('Export file is not ready for preview.');
        }

        $disk = $run->storage_disk ?: (string) config('export.disk', 'local');
        if (! Storage::disk($disk)->exists($run->storage_path)) {
            throw new RuntimeException('Export file missing from storage.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'export_preview_');
        if ($tmp === false) {
            throw new RuntimeException('Unable to create temp file for preview.');
        }
        file_put_contents($tmp, Storage::disk($disk)->get($run->storage_path));

        try {
            $spreadsheet = IOFactory::load($tmp);
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = (int) $sheet->getHighestDataRow();
            $highestColumn = $sheet->getHighestDataColumn();
            $headers = [];
            $colIndex = 1;
            foreach ($sheet->rangeToArray('A1:'.$highestColumn.'1')[0] ?? [] as $header) {
                $headers[] = (string) ($header ?? 'Column '.$colIndex);
                $colIndex++;
            }

            $dataRows = max(0, $highestRow - 1);
            $pageLimit = $limit ?? (int) config('export.preview_page_limit', 200);
            $pageMax = (int) config('export.preview_page_max', 500);
            $allMax = (int) config('export.preview_max_all_rows', 5000);

            if ($mode === 'all') {
                $offset = 0;
                $pageLimit = min($dataRows, $allMax);
            } else {
                $pageLimit = min(max(1, $pageLimit), $pageMax);
                $offset = max(0, $offset);
            }

            $rows = [];
            $start = 2 + $offset;
            $end = min($highestRow, $start + $pageLimit - 1);
            if ($start <= $highestRow) {
                $chunk = $sheet->rangeToArray('A'.$start.':'.$highestColumn.$end, null, true, true, false);
                foreach ($chunk as $row) {
                    $rows[] = array_values($row);
                }
            }

            $spreadsheet->disconnectWorksheets();

            return [
                'headers' => $headers,
                'rows' => $rows,
                'offset' => $offset,
                'limit' => $pageLimit,
                'total_rows' => $dataRows,
                'has_more' => ($offset + count($rows)) < $dataRows,
            ];
        } finally {
            @unlink($tmp);
        }
    }
}
