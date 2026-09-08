<?php

namespace Tests\Feature\Export;

use App\Models\ExportRun;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExportPreviewTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function preview_reads_generated_xlsx_file(): void
    {
        Storage::fake('local');
        config(['export.disk' => 'local']);

        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();
        WorkspaceMembership::factory()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);

        $token = (string) Str::uuid();
        $path = "exports/{$workspace->id}/preview_{$token}.xlsx";
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->fromArray([['A', 'B'], ['1', '2'], ['3', '4']], null, 'A1');
        (new Xlsx($sheet))->save($tmp);
        Storage::disk('local')->put($path, file_get_contents($tmp) ?: '');
        @unlink($tmp);

        ExportRun::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'token' => $token,
            'report_type' => 'orders',
            'target_module' => 'orders',
            'format' => 'xlsx',
            'status' => 'completed',
            'progress' => 100,
            'storage_disk' => 'local',
            'storage_path' => $path,
            'row_count' => 2,
        ]);

        $this->actingAs($user)
            ->withSession(['workspace_id' => $workspace->id])
            ->getJson(route('exports.preview', ['token' => $token, 'mode' => 'page', 'limit' => 10]))
            ->assertOk()
            ->assertJsonPath('headers.0', 'A')
            ->assertJsonPath('total_rows', 2)
            ->assertJsonCount(2, 'rows');
    }
}
