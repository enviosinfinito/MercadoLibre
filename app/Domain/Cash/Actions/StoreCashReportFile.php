<?php

namespace App\Domain\Cash\Actions;

use App\Models\CashReportFile;
use App\Models\Connection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class StoreCashReportFile
{
    /**
     * Persist downloaded CSV and prune older files for the same connection+kind.
     *
     * @return CashReportFile
     */
    public function execute(
        Connection $connection,
        string $reportKind,
        string $remoteFileName,
        string $csvBody,
        string $reportShape,
        int $rowsCount,
        ?CarbonImmutable $begin = null,
        ?CarbonImmutable $end = null,
        ?string $reportId = null,
    ): CashReportFile {
        $safeName = Str::of($remoteFileName)
            ->replace(['/', '\\'], '-')
            ->toString();
        if ($safeName === '') {
            $safeName = $reportKind.'-report.csv';
        }
        if (! str_ends_with(strtolower($safeName), '.csv')) {
            $safeName .= '.csv';
        }

        $relativeDir = sprintf(
            'cash-reports/%d/%d/%s',
            (int) $connection->workspace_id,
            (int) $connection->id,
            $reportKind,
        );
        $relativePath = $relativeDir.'/'.now()->format('Y-m-d_His').'_'.$safeName;

        Storage::disk('local')->put($relativePath, $csvBody);

        $file = CashReportFile::query()->create([
            'workspace_id' => $connection->workspace_id,
            'connection_id' => $connection->id,
            'report_kind' => $reportKind,
            'remote_file_name' => $remoteFileName,
            'storage_path' => $relativePath,
            'report_shape' => $reportShape,
            'rows_count' => max(0, $rowsCount),
            'bytes' => strlen($csvBody),
            'begin_date' => $begin,
            'end_date' => $end,
            'report_id' => $reportId,
        ]);

        $this->pruneOldFiles($connection, $reportKind, (int) $file->id);

        return $file;
    }

    private function pruneOldFiles(Connection $connection, string $reportKind, int $keepId): void
    {
        $keep = max(1, (int) config('finance.mercadopago.report_files_keep', 5));

        $old = CashReportFile::query()
            ->where('connection_id', $connection->id)
            ->where('report_kind', $reportKind)
            ->where('id', '!=', $keepId)
            ->orderByDesc('id')
            ->skip($keep - 1)
            ->take(100)
            ->get();

        foreach ($old as $file) {
            if ($file->existsOnDisk()) {
                Storage::disk('local')->delete($file->storage_path);
            }
            $file->delete();
        }
    }
}
