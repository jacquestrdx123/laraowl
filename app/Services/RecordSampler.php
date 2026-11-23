<?php

namespace App\Services;

use App\Models\Project;

/**
 * Decides whether a raw record is stored or only counted in the rollups.
 *
 * Rollups are written for every record regardless, so sampling only thins
 * the drill-down rows. See `laraowl.records.sampling` for the knobs.
 */
class RecordSampler
{
    public function shouldStore(Project $project, string $type, array $payload): bool
    {
        $rate = $this->rateFor($project, $type);

        if ($rate >= 1.0 || in_array($type, $this->neverSample(), true)) {
            return true;
        }

        if ($this->isSlow($payload) || $this->isFailure($payload)) {
            return true;
        }

        if ($rate <= 0.0) {
            return false;
        }

        return $this->roll($payload) < $rate;
    }

    public function rateFor(Project $project, string $type): float
    {
        $override = $project->settings['record_sample_rates'][$type] ?? null;

        $rate = is_numeric($override)
            ? (float) $override
            : (float) (config('laraowl.records.sampling.rates', [])[$type] ?? 1.0);

        return max(0.0, min(1.0, $rate));
    }

    /**
     * A number in [0, 1). Keyed on the trace id when there is one, so every
     * record of a sampled trace is kept and every record of a dropped one is
     * dropped, even across ingest batches and workers.
     */
    protected function roll(array $payload): float
    {
        $traceId = $payload['trace_id'] ?? null;

        if (is_string($traceId) && $traceId !== '') {
            return (crc32($traceId) % 10000) / 10000;
        }

        return mt_rand(0, 9999) / 10000;
    }

    /**
     * Durations arrive in microseconds.
     */
    protected function isSlow(array $payload): bool
    {
        $duration = $payload['duration'] ?? null;
        $thresholdMs = (float) config('laraowl.records.sampling.keep_slower_than_ms', 100);

        return is_numeric($duration) && $thresholdMs > 0 && (float) $duration >= $thresholdMs * 1000;
    }

    protected function isFailure(array $payload): bool
    {
        $status = $payload['status'] ?? null;
        $statusCode = $payload['status_code'] ?? null;

        return $status === 'failed'
            || (is_numeric($statusCode) && (int) $statusCode >= 400)
            || ! empty($payload['exception']);
    }

    /**
     * @return list<string>
     */
    protected function neverSample(): array
    {
        return config('laraowl.records.sampling.never_sample', []);
    }
}
