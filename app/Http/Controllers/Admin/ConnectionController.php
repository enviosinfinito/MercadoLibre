<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Shared\Support\AdminAudit;
use App\Http\Controllers\Controller;
use App\Http\Filters\Admin\ConnectionFilterRegistry;
use App\Http\Requests\Admin\ConnectionsIndexFilterRequest;
use App\Http\Requests\Admin\UpdateConnectionRequest;
use App\Models\Connection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConnectionController extends Controller
{
    public function index(ConnectionsIndexFilterRequest $request, ConnectionFilterRegistry $filterRegistry): Response
    {
        $filters = $request->filters();

        $query = Connection::query()
            ->withoutGlobalScope('workspace')
            ->with('workspace:id,name,slug')
            ->orderByDesc('id');

        $filterRegistry->apply($query, $filters);

        $connections = $query->paginate(25)->withQueryString();

        return Inertia::render('Admin/Connections/Index', [
            'connections' => $connections,
            'filters' => $filters,
        ]);
    }

    public function show(Connection $connection): Response
    {
        $connection = Connection::query()
            ->withoutGlobalScope('workspace')
            ->with('workspace:id,name,slug')
            ->whereKey($connection->id)
            ->firstOrFail();

        return Inertia::render('Admin/Connections/Show', [
            'connection' => $connection,
        ]);
    }

    public function update(UpdateConnectionRequest $request, Connection $connection): RedirectResponse
    {
        $connection = Connection::query()
            ->withoutGlobalScope('workspace')
            ->whereKey($connection->id)
            ->firstOrFail();

        $connection->update($request->validated());

        AdminAudit::log($request, 'admin.connection.updated', $connection, $connection->workspace_id, [
            'changes' => $request->validated(),
        ]);

        return redirect()
            ->route('admin.connections.show', $connection)
            ->with('success', 'Connection updated.');
    }

    public function destroy(Request $request, Connection $connection): RedirectResponse
    {
        $connection = Connection::query()
            ->withoutGlobalScope('workspace')
            ->whereKey($connection->id)
            ->firstOrFail();

        $workspaceId = $connection->workspace_id;

        AdminAudit::log($request, 'admin.connection.deleted', $connection, $workspaceId, [
            'provider' => $connection->provider,
            'external_user_id' => $connection->external_user_id,
        ]);

        $connection->credential?->delete();
        $connection->delete();

        return redirect()
            ->route('admin.connections.index')
            ->with('success', 'Connection disconnected.');
    }
}
