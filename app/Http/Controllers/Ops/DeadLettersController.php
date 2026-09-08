<?php

namespace App\Http\Controllers\Ops;

use App\Domain\Shared\Support\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\DeadLetter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class DeadLettersController extends Controller
{
    public function index(): Response
    {
        $items = DeadLetter::query()
            ->where('workspace_id', TenantContext::id())
            ->orderByDesc('failed_at')
            ->paginate(25);

        return Inertia::render('Ops/DeadLetters', [
            'deadLetters' => $items,
        ]);
    }

    public function replay(Request $request, DeadLetter $deadLetter): RedirectResponse
    {
        abort_unless((int) $deadLetter->workspace_id === TenantContext::id(), 404);

        Log::info('ops.dead_letter.replay_stub', [
            'dead_letter_id' => $deadLetter->id,
            'job_class' => $deadLetter->job_class,
        ]);

        $deadLetter->resolved_at = now();
        $deadLetter->resolution_notes = 'replay_stub';
        $deadLetter->save();

        return redirect()->route('ops.dead-letters.index')->with('success', 'Replay stubbed');
    }
}
