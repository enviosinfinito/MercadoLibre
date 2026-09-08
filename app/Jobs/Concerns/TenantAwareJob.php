<?php

namespace App\Jobs\Concerns;

use App\Domain\Shared\Support\TenantContext;
use App\Integrations\Support\SyncHttpLogContext;
use App\Models\Connection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

abstract class TenantAwareJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Not readonly: PHP cannot re-init parent readonly props when queue workers
     * unserialize a child job class.
     */
    public function __construct(
        public int $workspaceId,
        public int $connectionId,
    ) {}

    protected function bindTenant(): void
    {
        TenantContext::set($this->workspaceId);

        $provider = null;
        try {
            $provider = Connection::query()->whereKey($this->connectionId)->value('provider');
        } catch (\Throwable) {
            $provider = null;
        }

        SyncHttpLogContext::bind(
            workspaceId: $this->workspaceId,
            connectionId: $this->connectionId,
            provider: is_string($provider) ? $provider : null,
        );
    }

    public function handle(): void
    {
        $this->bindTenant();

        try {
            $this->handleForTenant();
        } finally {
            SyncHttpLogContext::clear();
            TenantContext::clear();
        }
    }

    abstract protected function handleForTenant(): void;
}
