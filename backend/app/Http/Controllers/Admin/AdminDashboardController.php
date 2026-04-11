<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiUsage;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class AdminDashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $today = today();

        return $this->success([
            'active_users'       => User::where('status', 'active')->count(),
            'suspended_users'    => User::where('status', 'suspended')->count(),
            'new_signups_today'  => User::whereDate('created_at', $today)->count(),
            'total_api_calls_today' => ApiUsage::whereDate('created_at', $today)->count(),
            'mrr_cents'          => $this->calculateMrr(),
            'signups_last_30_days' => $this->signupsLast30Days(),
            'plan_distribution'  => $this->planDistribution(),
        ]);
    }

    private function calculateMrr(): int
    {
        $planPrices = [
            'starter'    => 2900,
            'pro'        => 9900,
            'enterprise' => 49900,
        ];

        return Subscription::where('status', 'active')
            ->whereIn('plan', array_keys($planPrices))
            ->get()
            ->sum(fn ($sub) => $planPrices[$sub->plan] ?? 0);
    }

    private function signupsLast30Days(): array
    {
        return User::selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->where('created_at', '>=', now()->subDays(29)->startOfDay())
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => ['date' => $row->date, 'count' => (int) $row->count])
            ->toArray();
    }

    private function planDistribution(): array
    {
        return Subscription::selectRaw('plan, COUNT(*) as count')
            ->where('status', 'active')
            ->groupBy('plan')
            ->pluck('count', 'plan')
            ->toArray();
    }
}
