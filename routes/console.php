<?php

use App\Jobs\RecoverMissedFeedsJob;
use App\Models\Connection;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('automation:run')->everyFifteenMinutes();

Schedule::command('connections:refresh-expiring-tokens')
    ->everyFifteenMinutes()
    ->name('refresh-expiring-ml-tokens');

Schedule::command('connections:enrich-mercadolibre-profiles --force')
    ->dailyAt('03:15')
    ->name('enrich-ml-connection-profiles');

Schedule::call(function () {
    $to = now();
    $from = $to->copy()->subHours(6);

    Connection::query()
        ->where('status', 'active')
        ->orderBy('id')
        ->each(function (Connection $connection) use ($from, $to) {
            RecoverMissedFeedsJob::dispatch(
                (int) $connection->workspace_id,
                (int) $connection->id,
                $from->toIso8601String(),
                $to->toIso8601String(),
            );
        });
})->hourly()->name('recover-missed-feeds');

Schedule::command('amazon:sqs-poll')
    ->everyFiveMinutes()
    ->when(fn () => filled(config('connectors.amazon.sqs_queue_url'))
        || (bool) env('AMAZON_SQS_POLL_ENABLED', false));

Schedule::command('reports:run-scheduled')
    ->everyMinute()
    ->name('process-scheduled-exports');

Schedule::command('export:prune-files')
    ->daily()
    ->name('export-prune-files');

Schedule::command('returns:refresh-analytics --days=3')
    ->dailyAt('02:30')
    ->name('returns-refresh-analytics');

Schedule::command('ads:sync-mercadolibre --days=14')
    ->dailyAt('10:30')
    ->name('ads-sync-mercadolibre');

Schedule::command('ads:evaluate-rules --execute-approved')
    ->everyFourHours()
    ->name('ads-evaluate-rules');

Schedule::command('finance:probe-cash-apis')
    ->weeklyOn(1, '04:00')
    ->name('finance-probe-cash-apis');

Schedule::command('finance:sync-cash-reports --settlement --billing --reconcile')
    ->dailyAt('05:30')
    ->name('finance-sync-cash-settlement');

Schedule::command('finance:sync-cash-reports --release')
    ->dailyAt('05:45')
    ->name('finance-sync-cash-release');

Schedule::command('horizon:snapshot')->everyFiveMinutes();

Schedule::command('telescope:prune-by-size')
    ->everyFifteenMinutes()
    ->name('telescope-prune-by-size');
