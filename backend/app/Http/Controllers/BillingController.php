<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    private const PLAN_PRICES = [
        'starter'    => 2900,
        'pro'        => 9900,
        'enterprise' => 49900,
    ];

    private const PLAN_LABELS = [
        'free'       => 'Free',
        'starter'    => 'Starter',
        'pro'        => 'Pro',
        'enterprise' => 'Enterprise',
    ];

    public function currentPlan(Request $request): JsonResponse
    {
        $user = $request->user()->load('subscription');
        $sub  = $user->subscription;

        return $this->success([
            'plan'                 => $sub?->plan ?? 'free',
            'plan_label'           => self::PLAN_LABELS[$sub?->plan ?? 'free'],
            'status'               => $sub?->status ?? 'none',
            'current_period_start' => $sub?->current_period_start?->toIso8601String(),
            'current_period_end'   => $sub?->current_period_end?->toIso8601String(),
            'canceled_at'          => $sub?->canceled_at?->toIso8601String(),
            'available_plans'      => $this->availablePlans(),
        ]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $request->validate([
            'plan' => ['required', 'in:starter,pro,enterprise'],
        ]);

        $user = $request->user();
        $plan = $request->plan;

        // Upsert subscription (mock: no real Stripe call)
        $subscription = Subscription::updateOrCreate(
            ['user_id' => $user->id],
            [
                'plan'                 => $plan,
                'status'               => 'active',
                'current_period_start' => now(),
                'current_period_end'   => now()->addMonth(),
                'canceled_at'          => null,
            ]
        );

        // Record payment (mock)
        Payment::create([
            'user_id'     => $user->id,
            'amount'      => self::PLAN_PRICES[$plan],
            'currency'    => 'usd',
            'status'      => 'succeeded',
            'description' => 'Subscription — '.self::PLAN_LABELS[$plan].' Plan',
        ]);

        // Sync API key tier to match new plan
        $user->apiKeys()->whereNull('deleted_at')->update(
            array_merge(['tier' => $plan], ApiKey::tierDefaults($plan))
        );

        return $this->success([
            'subscription' => [
                'plan'   => $subscription->plan,
                'status' => $subscription->status,
            ],
        ]);
    }

    public function cancelSubscription(Request $request): JsonResponse
    {
        $user = $request->user();
        $sub  = $user->subscription;

        if (! $sub || $sub->status !== 'active') {
            return $this->error('NO_ACTIVE_SUBSCRIPTION', 'No active subscription found.', 422);
        }

        $sub->update([
            'status'      => 'canceled',
            'canceled_at' => now(),
        ]);

        // Downgrade API keys to free tier
        $user->apiKeys()->whereNull('deleted_at')->update(
            array_merge(['tier' => 'free'], ApiKey::tierDefaults('free'))
        );

        return $this->success(['message' => 'Subscription canceled.']);
    }

    private function availablePlans(): array
    {
        return [
            ['key' => 'starter',    'label' => 'Starter',    'price_cents' => 2900,  'price_formatted' => '$29/mo',  'api_calls' => '10,000/day',   'webhooks' => false],
            ['key' => 'pro',        'label' => 'Pro',        'price_cents' => 9900,  'price_formatted' => '$99/mo',  'api_calls' => '100,000/day',  'webhooks' => true],
            ['key' => 'enterprise', 'label' => 'Enterprise', 'price_cents' => 49900, 'price_formatted' => '$499/mo', 'api_calls' => 'Unlimited',    'webhooks' => true],
        ];
    }
}
