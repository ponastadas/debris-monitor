<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiUsage;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $today = today();

        return $this->success([
            'active_users'          => User::where('status', 'active')->count(),
            'suspended_users'       => User::where('status', 'suspended')->count(),
            'new_signups_today'     => User::whereDate('created_at', $today)->count(),
            'total_api_calls_today' => ApiUsage::whereDate('created_at', $today)->count(),
            'mrr_cents'             => $this->calculateMrr(),
            'signups_last_30_days'  => $this->signupsLast30Days(),
            'plan_distribution'     => $this->planDistribution(),
            'catalog'               => $this->catalogStats(),
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

    /**
     * Catalog health snapshot — total satellites with current TLE, last sync time, count by type.
     * Returns zeros/null when the catalog has never been synced.
     */
    private function catalogStats(): array
    {
        $total = DB::table('tle_records')->where('is_current', true)->count();

        $syncedAt = null;
        if ($total > 0) {
            $max      = DB::table('tle_records')->where('is_current', true)->max('fetched_at');
            $syncedAt = $max ? Carbon::parse($max)->toIso8601String() : null;
        }

        $typeMap = ['satellite' => 'satellite', 'debris' => 'debris', 'rocket_body' => 'rocket'];

        $byType = DB::table('satellites as s')
            ->join('tle_records as t', function ($join) {
                $join->on('t.satellite_id', '=', 's.id')->where('t.is_current', true);
            })
            ->select('s.object_type', DB::raw('COUNT(*) as count'))
            ->groupBy('s.object_type')
            ->get()
            ->mapWithKeys(fn ($row) => [$typeMap[$row->object_type] ?? 'unknown' => (int) $row->count])
            ->toArray();

        return [
            'total'     => $total,
            'synced_at' => $syncedAt,
            'by_type'   => $byType,
        ];
    }
}
