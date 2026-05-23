<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function currentPlan(Request $request): JsonResponse
    {
        $user = $request->user()->load('subscription');
        $sub = $user->subscription;
        $plan = $user->currentPlan();

        return $this->success([
            'plan' => $plan,
            'plan_label' => EntitlementService::label($plan),
            'status' => $sub?->status ?? 'none',
            'current_period_start' => $sub?->current_period_start?->toIso8601String(),
            'current_period_end' => $sub?->current_period_end?->toIso8601String(),
            'canceled_at' => $sub?->canceled_at?->toIso8601String(),
            'entitlements' => EntitlementService::forUser($user),
            'available_plans' => EntitlementService::catalog(),
        ]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $request->validate([
            'plan' => ['required', 'in:'.implode(',', EntitlementService::paidPlanKeys())],
        ]);

        $user = $request->user();
        $plan = $request->string('plan')->value();

        // Mock subscription upsert (no real Stripe call).
        // On Cashier cutover: replace this block with $user->newSubscription('default', $priceId)->create($paymentMethod).
        Subscription::updateOrCreate(
            ['user_id' => $user->id],
            [
                'name' => 'default',
                'plan' => $plan,
                'status' => 'active',
                'current_period_start' => now(),
                'current_period_end' => now()->addMonth(),
                'canceled_at' => null,
            ]
        );

        // Record mock payment.
        // On Cashier cutover: payment records come from Stripe webhooks — delete this block.
        Payment::create([
            'user_id' => $user->id,
            'amount' => EntitlementService::priceCents($plan),
            'currency' => 'usd',
            'status' => 'succeeded',
            'description' => 'Subscription — '.EntitlementService::label($plan).' Plan',
        ]);

        // Sync API key tier to match new plan.
        $user->apiKeys()->whereNull('deleted_at')->update(
            array_merge(['tier' => $plan], ApiKey::tierDefaults($plan))
        );

        $user->refresh()->load('subscription');

        return $this->success([
            'plan' => $plan,
            'plan_label' => EntitlementService::label($plan),
            'status' => 'active',
            'entitlements' => EntitlementService::forUser($user),
        ]);
    }

    public function cancelSubscription(Request $request): JsonResponse
    {
        $user = $request->user();
        $sub = $user->subscription;

        if (! $sub || $sub->status !== 'active') {
            return $this->error('NO_ACTIVE_SUBSCRIPTION', 'No active subscription found.', 422);
        }

        // Mock cancel. On Cashier cutover: $user->subscription('default')->cancel().
        $sub->update([
            'status' => 'canceled',
            'canceled_at' => now(),
        ]);

        // Downgrade API keys to free tier on cancel.
        $user->apiKeys()->whereNull('deleted_at')->update(
            array_merge(['tier' => 'free'], ApiKey::tierDefaults('free'))
        );

        return $this->success(['message' => 'Subscription canceled. You have been moved to the free plan.']);
    }

    public function paymentHistory(Request $request): JsonResponse
    {
        $payments = $request->user()
            ->payments()
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (Payment $p) => [
                'id' => $p->id,
                'amount' => $p->amount,
                'formatted' => $p->formattedAmount(),
                'currency' => strtoupper($p->currency),
                'status' => $p->status,
                'description' => $p->description,
                'refunded_at' => $p->refunded_at?->toIso8601String(),
                'created_at' => $p->created_at->toIso8601String(),
            ]);

        return $this->success($payments);
    }
}
