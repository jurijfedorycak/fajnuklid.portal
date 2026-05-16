<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Applies admin-configured piecewise rounding to a cleaning visit duration.
 *
 * Rules are a list of milestones, each defining the lower bound of a range
 * and how durations inside that range should be rounded. Sorted by
 * threshold_minutes ascending, rule i covers [threshold_i, threshold_{i+1});
 * the last rule extends to +∞. Durations not covered by any rule (e.g.
 * shorter than the lowest threshold) are returned unchanged so a partially
 * configured rule set never silently swallows visits.
 *
 * The service has no DB access on purpose — keeping it pure makes the
 * mapping easy to unit-test and lets the FE re-implement the same logic in
 * JS for the live preview without going through the API.
 */
class TimeRoundingService
{
    /**
     * Round a raw visit duration in minutes to the value billable for the client.
     *
     * @param int $minutes Raw duration in minutes (computed from FreshQR scan
     *                     times). Non-positive values are returned as-is —
     *                     callers treat those as "no rounding possible".
     * @param array<int,array{threshold_minutes:int,interval_minutes:int,direction:string}> $rules
     *                     Already validated by the admin layer; this method
     *                     trusts the shape and only sorts defensively.
     */
    public static function roundDuration(int $minutes, array $rules): int
    {
        if ($minutes <= 0 || $rules === []) {
            return $minutes;
        }

        $rule = self::findMatchingRule($minutes, $rules);
        if ($rule === null) {
            return $minutes;
        }

        $interval = (int) $rule['interval_minutes'];
        $direction = (string) $rule['direction'];

        if ($direction === 'none' || $interval <= 0) {
            return $minutes;
        }

        return match ($direction) {
            'up'      => (int) (ceil($minutes / $interval) * $interval),
            'down'    => intdiv($minutes, $interval) * $interval,
            'nearest' => (int) (round($minutes / $interval, 0, PHP_ROUND_HALF_UP) * $interval),
            default   => $minutes,
        };
    }

    /**
     * Walk the rule list in ascending threshold order and return the last rule
     * whose threshold does not exceed $minutes. Returns null when every rule's
     * threshold is greater than $minutes (the duration sits below the lowest
     * milestone) — the safe-default behaviour leaves the value unrounded.
     *
     * @param array<int,array<string,mixed>> $rules
     * @return array<string,mixed>|null
     */
    private static function findMatchingRule(int $minutes, array $rules): ?array
    {
        $sorted = $rules;
        usort($sorted, static function (array $a, array $b): int {
            return ((int) ($a['threshold_minutes'] ?? 0)) <=> ((int) ($b['threshold_minutes'] ?? 0));
        });

        $matched = null;
        foreach ($sorted as $rule) {
            $threshold = (int) ($rule['threshold_minutes'] ?? 0);
            if ($threshold <= $minutes) {
                $matched = $rule;
            } else {
                break;
            }
        }

        return $matched;
    }
}
