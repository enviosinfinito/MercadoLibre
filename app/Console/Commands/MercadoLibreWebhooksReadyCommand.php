<?php

namespace App\Console\Commands;

use App\Domain\Shared\Support\PublicAppUrl;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

class MercadoLibreWebhooksReadyCommand extends Command
{
    protected $signature = 'meli:webhooks-ready
                            {--skip-http : Skip probing the public tunnel URL}';

    protected $description = 'Verify local readiness for Mercado Libre live order webhooks and print the ML console checklist';

    public function handle(): int
    {
        $ok = true;
        $base = PublicAppUrl::base();
        $callback = PublicAppUrl::to('webhooks/mercadolibre');
        $secret = trim((string) config('connectors.mercadolibre.webhook_secret', ''));
        if ($secret !== '') {
            $callback .= (str_contains($callback, '?') ? '&' : '?').'secret='.rawurlencode($secret);
        }

        $oauth = (string) config('connectors.mercadolibre.redirect_uri', '');
        $appId = (string) (config('connectors.mercadolibre.client_id') ?: '');

        $this->info('Mercado Libre — checklist consola de desarrolladores');
        $this->line('  Notification callback URL (exacta):');
        $this->line('    '.$callback);
        $this->line('  Topics mínimos: orders_v2, orders');
        $this->line('  OAuth redirect URI:');
        $this->line('    '.($oauth !== '' ? $oauth : '(MELI_REDIRECT_URI vacío)'));
        $this->line('  App / Client ID esperado en application_id: '.($appId !== '' ? $appId : '(sin configurar)'));
        $this->newLine();

        if ($appId === '') {
            $this->error('[fail] MELI_CLIENT_ID / MELI_APP_ID no configurado');
            $ok = false;
        } else {
            $this->info('[ok] MELI_CLIENT_ID configurado');
        }

        if ($secret === '') {
            $this->warn('[warn] MELI_WEBHOOK_SECRET vacío — solo se valida application_id');
        } else {
            $this->info('[ok] MELI_WEBHOOK_SECRET configurado (usar la URL con ?secret= en la consola ML)');
        }

        $stub = filter_var(env('MELI_WEBHOOKS_STUB', true), FILTER_VALIDATE_BOOLEAN);
        $this->line($stub
            ? '[info] MELI_WEBHOOKS_STUB=true — la suscripción real es solo en la consola ML'
            : '[info] MELI_WEBHOOKS_STUB=false');

        try {
            Artisan::call('horizon:status');
            $horizonOut = trim(Artisan::output());
            if (str_contains(strtolower($horizonOut), 'running')) {
                $this->info('[ok] Horizon running (colas webhook-ingress / critical-sync)');
            } else {
                $this->error('[fail] Horizon no está running: '.$horizonOut);
                $ok = false;
            }
        } catch (\Throwable $e) {
            $this->error('[fail] No se pudo consultar Horizon: '.$e->getMessage());
            $ok = false;
        }

        if (! $this->option('skip-http')) {
            try {
                $up = Http::timeout(10)->get(rtrim($base, '/').'/up');
                if ($up->successful()) {
                    $this->info('[ok] Túnel/público responde /up → '.$base);
                } else {
                    $this->error('[fail] /up vía URL pública devolvió HTTP '.$up->status());
                    $ok = false;
                }

                $probe = Http::timeout(10)->asJson()->post($callback, [
                    'resource' => '/orders/0',
                    'user_id' => 0,
                    'topic' => 'orders_v2',
                    'application_id' => $appId !== '' ? (int) $appId : 0,
                    '_id' => 'meli-webhooks-ready-probe',
                ]);
                if ($probe->status() === 200) {
                    $this->info('[ok] POST /webhooks/mercadolibre acepta (HTTP 200)');
                } elseif ($probe->status() === 401) {
                    $this->error('[fail] Webhook devolvió 401 — revisá MELI_WEBHOOK_SECRET / application_id');
                    $ok = false;
                } else {
                    $this->error('[fail] Webhook probe HTTP '.$probe->status());
                    $ok = false;
                }
            } catch (\Throwable $e) {
                $this->error('[fail] URL pública no alcanzable (¿túnel caído?): '.$e->getMessage());
                $this->line('  Regenerá el túnel y actualizá APP_PUBLIC_URL + MELI_REDIRECT_URI + consola ML.');
                $ok = false;
            }
        }

        $this->newLine();
        if ($ok) {
            $this->info('Listo de este lado. Confirmá en la consola ML que el callback coincide con la URL de arriba.');

            return self::SUCCESS;
        }

        $this->error('Hay pendientes antes de recibir ventas live por webhook.');

        return self::FAILURE;
    }
}
