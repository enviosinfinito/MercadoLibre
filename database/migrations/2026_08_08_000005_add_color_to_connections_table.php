<?php

use App\Support\ConnectionColorPalette;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->string('color', 7)->nullable()->after('power_seller_status');
        });

        $connections = DB::table('connections')
            ->orderBy('workspace_id')
            ->orderBy('id')
            ->get(['id', 'workspace_id']);

        $indexByWorkspace = [];

        foreach ($connections as $connection) {
            $workspaceId = (int) $connection->workspace_id;
            $index = $indexByWorkspace[$workspaceId] ?? 0;
            $color = ConnectionColorPalette::COLORS[$index % count(ConnectionColorPalette::COLORS)];
            $indexByWorkspace[$workspaceId] = $index + 1;

            DB::table('connections')
                ->where('id', $connection->id)
                ->update(['color' => $color]);
        }
    }

    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
};
