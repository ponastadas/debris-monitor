<?php

namespace App\Services;

use DateTime;

/**
 * Simplified Keplerian propagator.
 *
 * Accuracy is sufficient for conjunction screening (detecting approaches
 * within tens of km). For operational-grade results, replace with a full
 * SGP4 implementation or delegate to the Space-Track CDM API.
 *
 * The algorithm mirrors the tleToPosition() function used in
 * frontend/src/DebrisMonitor.jsx so both sides agree on positions.
 */
class TlePropagator
{
    private const MU = 398600.4418; // km³/s²

    private const EARTH_RADIUS = 6371.0;      // km

    private const MISS_THRESHOLD_KM = 5.0;

    /**
     * Propagate a TLE to a given time and return ECI position in km.
     *
     * @return array{x: float, y: float, z: float}|null null on parse error
     */
    public function propagate(string $line1, string $line2, DateTime $time): ?array
    {
        // ── Parse epoch ──────────────────────────────────────────────────
        $epochStr = substr($line1, 18, 14);
        $year2 = (int) substr($epochStr, 0, 2);
        $year = $year2 >= 57 ? 1900 + $year2 : 2000 + $year2;
        $doy = (float) substr($epochStr, 2);   // day of year (fractional)

        $epochTs = mktime(0, 0, 0, 1, 1, $year) + ($doy - 1) * 86400.0;

        // ── Parse orbital elements from line 2 ───────────────────────────
        $incl = (float) substr($line2, 8, 8);
        $raan = (float) substr($line2, 17, 8);
        $ecc = (float) ('0.'.substr($line2, 26, 7));
        $argPer = (float) substr($line2, 34, 8);
        $m0 = (float) substr($line2, 43, 8);   // mean anomaly at epoch (deg)
        $n = (float) substr($line2, 52, 11);  // mean motion (rev/day)

        if ($n <= 0) {
            return null;
        }

        $nRad = $n * 2 * M_PI / 86400.0;             // rad/s
        $a = pow(self::MU / ($nRad ** 2), 1 / 3); // semi-major axis (km)

        // ── Propagate mean anomaly ────────────────────────────────────────
        $dt = (float) $time->getTimestamp() - $epochTs; // seconds since epoch
        $M = fmod(deg2rad($m0) + $nRad * $dt, 2 * M_PI);
        if ($M < 0) {
            $M += 2 * M_PI;
        }

        // ── Eccentric anomaly (Newton-Raphson, 8 iterations) ─────────────
        $E = $M;
        for ($i = 0; $i < 8; $i++) {
            $E -= ($E - $ecc * sin($E) - $M) / (1.0 - $ecc * cos($E));
        }

        // ── True anomaly ─────────────────────────────────────────────────
        $cosE = cos($E);
        $sinE = sin($E);
        $v = atan2(
            sqrt(1 - $ecc ** 2) * $sinE,
            $cosE - $ecc
        );

        // ── Orbital radius ────────────────────────────────────────────────
        $r = $a * (1 - $ecc * $cosE);

        // ── Perifocal → ECI rotation ──────────────────────────────────────
        $w = deg2rad($argPer);
        $i_rad = deg2rad($incl);
        $O = deg2rad($raan);

        $cosW = cos($w);
        $sinW = sin($w);
        $cosI = cos($i_rad);
        $sinI = sin($i_rad);
        $cosO = cos($O);
        $sinO = sin($O);

        $xp = $r * cos($v);
        $yp = $r * sin($v);

        $x = $xp * ($cosW * $cosO - $sinW * $sinO * $cosI)
           + $yp * (-$sinW * $cosO - $cosW * $sinO * $cosI);

        $y = $xp * ($cosW * $sinO + $sinW * $cosO * $cosI)
           + $yp * (-$sinW * $sinO + $cosW * $cosO * $cosI);

        $z = $xp * ($sinW * $sinI)
           + $yp * ($cosW * $sinI);

        return ['x' => $x, 'y' => $y, 'z' => $z];
    }

    /**
     * Find the closest approach between two objects over a future window.
     *
     * Strategy: coarse pass at 5-min steps; refine any window with distance
     * < 100 km at 30-second steps to get an accurate TCA and miss distance.
     *
     * @return array{tca: DateTime, miss_km: float, risk_score: int}|null
     *                                                                    null when no approach comes within MISS_THRESHOLD_KM
     */
    public function findClosestApproach(
        string $tle1A, string $tle2A,
        string $tle1B, string $tle2B,
        DateTime $windowStart,
        int $days = 5
    ): ?array {
        $windowEnd = (clone $windowStart)->modify("+{$days} days");
        $coarseStep = 300;   // 5 minutes in seconds
        $fineStep = 30;    // 30 seconds
        $fineThreshold = 100.0; // km — triggers fine pass

        $bestDist = PHP_FLOAT_MAX;
        $bestTime = null;

        $ts = (int) $windowStart->getTimestamp();
        $te = (int) $windowEnd->getTimestamp();

        // ── Coarse pass ───────────────────────────────────────────────────
        $coarseHits = [];
        for ($t = $ts; $t <= $te; $t += $coarseStep) {
            $dt = new DateTime('@'.$t);
            $pA = $this->propagate($tle1A, $tle2A, $dt);
            $pB = $this->propagate($tle1B, $tle2B, $dt);
            if ($pA === null || $pB === null) {
                continue;
            }
            $d = $this->distance($pA, $pB);
            if ($d < $fineThreshold) {
                $coarseHits[] = $t;
            }
            if ($d < $bestDist) {
                $bestDist = $d;
                $bestTime = $dt;
            }
        }

        if ($bestDist > $fineThreshold) {
            // Nothing got close enough to be interesting
            return null;
        }

        // ── Fine pass around each coarse hit ─────────────────────────────
        foreach ($coarseHits as $hit) {
            $start = max($ts, $hit - $coarseStep);
            $end = min($te, $hit + $coarseStep);
            for ($t = $start; $t <= $end; $t += $fineStep) {
                $dt = new DateTime('@'.$t);
                $pA = $this->propagate($tle1A, $tle2A, $dt);
                $pB = $this->propagate($tle1B, $tle2B, $dt);
                if ($pA === null || $pB === null) {
                    continue;
                }
                $d = $this->distance($pA, $pB);
                if ($d < $bestDist) {
                    $bestDist = $d;
                    $bestTime = $dt;
                }
            }
        }

        if ($bestDist > self::MISS_THRESHOLD_KM) {
            return null;
        }

        return [
            'tca' => $bestTime,
            'miss_km' => round($bestDist, 3),
            'risk_score' => $this->riskScore($bestDist),
        ];
    }

    /** Euclidean distance between two ECI positions (km). */
    private function distance(array $a, array $b): float
    {
        return sqrt(
            ($a['x'] - $b['x']) ** 2 +
            ($a['y'] - $b['y']) ** 2 +
            ($a['z'] - $b['z']) ** 2
        );
    }

    /**
     * Map miss distance to a 0–100 risk score.
     * < 0.1 km → 100, 1 km → ~85, 5 km → 0.
     */
    private function riskScore(float $missKm): int
    {
        if ($missKm <= 0.0) {
            return 100;
        }
        $score = (int) round(100 * (1 - ($missKm / self::MISS_THRESHOLD_KM)));

        return max(0, min(100, $score));
    }
}
