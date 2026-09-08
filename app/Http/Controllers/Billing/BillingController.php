<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Shared\Support\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BillingController extends Controller
{
    public function index(): Response
    {
        $workspaceId = TenantContext::id();
        $workspace = Workspace::query()->findOrFail($workspaceId);

        $subscription = Subscription::query()
            ->where('workspace_id', $workspace->id)
            ->latest('id')
            ->first();

        $usage = UsageCounter::query()
            ->where('workspace_id', $workspace->id)
            ->where('period_key', now()->format('Y-m'))
            ->get(['metric_key', 'quantity', 'period_key']);

        return Inertia::render('Billing/Index', [
            'billing' => [
                'stripe_configured' => filled(config('services.stripe.secret')),
                'customer_portal_url' => null,
                'subscription' => $subscription,
                'usage' => $usage,
            ],
        ]);
    }

    /**
     * Stripe Customer Portal stub — returns to billing with a flash message until Cashier is wired.
     */
    public function portal(Request $request): RedirectResponse
    {
        if (! filled(config('services.stripe.secret'))) {
            return redirect()
                ->route('billing.index')
                ->with('error', 'Stripe is not configured.');
        }

        // Stub: real implementation would create a Stripe Billing Portal session.
        return redirect()
            ->route('billing.index')
            ->with('success', 'Stripe Customer Portal stub — configure Cashier to enable redirects.');
    }
}
