<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Models\User;

/**
 * Single source of truth for plan capabilities AND pricing.
 *
 * All actor types (guest / registered user / API key) resolve to the same
 * capability shape. No scattered plan checks in controllers.
 *
 * Adding Stripe/Cashier: map Cashier plan names to the keys in $plans — nothing else changes.
 * Adding add-ons:        add a flag here; check EntitlementService::can() in middleware/controllers.
 * Adding a new plan:     add an entry to $plans, $labels, and $pricing.
 */
class EntitlementService
{
    /** Internal plan keys → capability shape */
    private static array $plans = [
        'guest' => [
            'requests_per_day' => 10,
            'can_view_nearby_objects' => true,   // Tracker analysis — guest-limited by rate limit
            'can_view_alerts' => false,  // Alerts tab — subscription required
            'can_manage_watched_satellites' => false,  // Requires account
            'can_receive_alerts' => false,  // Alert notifications — subscription required
            'can_use_api_keys' => false,
            'webhooks_enabled' => false,
            'satellite_limit' => null,
        ],
        'free' => [
            'requests_per_day' => 500,
            'can_view_nearby_objects' => true,   // Tracker fully available on free
            'can_view_alerts' => false,  // Alerts tab — must upgrade
            'can_manage_watched_satellites' => true,   // Can watch up to satellite_limit
            'can_receive_alerts' => false,  // Notifications — must upgrade
            'can_use_api_keys' => true,
            'webhooks_enabled' => false,
            'satellite_limit' => 5,
        ],
        'starter' => [
            'requests_per_day' => 10_000,
            'can_view_nearby_objects' => true,
            'can_view_alerts' => true,
            'can_manage_watched_satellites' => true,
            'can_receive_alerts' => true,
            'can_use_api_keys' => true,
            'webhooks_enabled' => true,
            'satellite_limit' => null,
        ],
        'pro' => [
            'requests_per_day' => 100_000,
            'can_view_nearby_objects' => true,
            'can_view_alerts' => true,
            'can_manage_watched_satellites' => true,
            'can_receive_alerts' => true,
            'can_use_api_keys' => true,
            'webhooks_enabled' => true,
            'satellite_limit' => null,
        ],
        'enterprise' => [
            'requests_per_day' => null,
            'can_view_nearby_objects' => true,
            'can_view_alerts' => true,
            'can_manage_watched_satellites' => true,
            'can_receive_alerts' => true,
            'can_use_api_keys' => true,
            'webhooks_enabled' => true,
            'satellite_limit' => null,
        ],
    ];

    /** Human-readable labels for all plan keys */
    private static array $labels = [
        'guest' => 'Guest',
        'free' => 'Free',
        'starter' => 'Starter',
        'pro' => 'Pro',
        'enterprise' => 'Enterprise',
    ];

    /** Pricing for paid plans only (free/guest have no price) */
    private static array $pricing = [
        'starter' => ['price_cents' => 2900,  'price_formatted' => '$29/mo'],
        'pro' => ['price_cents' => 9900,  'price_formatted' => '$99/mo'],
        'enterprise' => ['price_cents' => 49900, 'price_formatted' => '$499/mo'],
    ];

    // ── Resolvers ─────────────────────────────────────────────────────────────

    public static function forGuest(): array
    {
        return self::$plans['guest'];
    }

    public static function forUser(User $user): array
    {
        $plan = $user->currentPlan();
        $base = self::$plans[$plan] ?? self::$plans['free'];

        // Merge per-user add-on overrides (users.addons JSON column).
        // Add-ons are a capability map, e.g. {"requests_per_day": 50000, "can_receive_alerts": true}.
        // They override the base plan for that user only — useful for lifetime deals, beta access, etc.
        if (! empty($user->addons)) {
            $base = array_merge($base, $user->addons);
        }

        return $base;
    }

    public static function forAdmin(): array
    {
        return self::$plans['enterprise'];
    }

    public static function forApiKey(ApiKey $key): array
    {
        $base = self::$plans[$key->tier] ?? self::$plans['free'];

        // Key-level values override plan defaults — the key is the contract with the developer.
        $base['requests_per_day'] = $key->daily_limit;
        $base['webhooks_enabled'] = $key->webhooks_enabled;
        $base['satellite_limit'] = $key->satellite_limit;

        return $base;
    }

    // ── Capability check ──────────────────────────────────────────────────────

    /**
     * Check a single capability against a resolved entitlements array.
     *
     * Usage: EntitlementService::can($entitlements, 'can_receive_alerts')
     */
    public static function can(array $entitlements, string $capability): bool
    {
        return (bool) ($entitlements[$capability] ?? false);
    }

    // ── Plan metadata ─────────────────────────────────────────────────────────

    /** Display label for a plan key. */
    public static function label(string $plan): string
    {
        return self::$labels[$plan] ?? ucfirst($plan);
    }

    /** Price in cents for a given paid plan (returns 0 for free/guest). */
    public static function priceCents(string $plan): int
    {
        return self::$pricing[$plan]['price_cents'] ?? 0;
    }

    /** Valid paid plan keys — use in validation rules instead of hardcoded strings. */
    public static function paidPlanKeys(): array
    {
        return array_keys(self::$pricing); // ['starter', 'pro', 'enterprise']
    }

    /**
     * Full plan catalog for frontend upgrade flows.
     * Returns only the upgradeable paid plans with capabilities + pricing.
     */
    public static function catalog(): array
    {
        $result = [];

        foreach (self::$pricing as $key => $pricing) {
            $caps = self::$plans[$key];
            $reqLimit = $caps['requests_per_day'];

            $result[] = [
                'key' => $key,
                'label' => self::$labels[$key],
                'price_cents' => $pricing['price_cents'],
                'price_formatted' => $pricing['price_formatted'],
                'requests_per_day' => $reqLimit,
                'requests_label' => $reqLimit !== null ? number_format($reqLimit).'/day' : 'Unlimited',
                'can_view_alerts' => $caps['can_view_alerts'],
                'can_manage_watched_satellites' => $caps['can_manage_watched_satellites'],
                'can_receive_alerts' => $caps['can_receive_alerts'],
                'webhooks_enabled' => $caps['webhooks_enabled'],
                'satellite_limit' => $caps['satellite_limit'],
            ];
        }

        return $result;
    }
}
