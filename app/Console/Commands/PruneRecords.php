<?php

namespace App\Console\Commands;

use App\Models\Project;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Deletes raw records (and uptime checks) past their retention window.
 *
 * Built for a table with tens of millions of rows that is being written to
 * the whole time: every delete touches at most `batch_size` rows through the
 * primary key or a leading-`project_id` index, with a short sleep in between,
 * so no statement holds locks for long and ingest keeps flowing.
 *
 * Replaces `model:prune` for records: its per-chunk `DELETE ... WHERE EXISTS
 * (correlated subquery) LIMIT 1000` has no index to stand on and rescans the
 * table for every chunk.
 */
class PruneRecords extends Command
{
    protected $signature = 'laraowl:records:prune
                            {--dry-run : Report the cutoffs without deleting anything}
                            {--max-seconds= : Stop after this long (default: laraowl.records.prune.max_seconds)}';

    protected $description = 'Delete raw records and uptime checks past their retention window, in small batches';

    protected int $batchSize;

    protected int $sleepMs;

    protected float $deadline;

    protected bool $outOfTime = false;

    public function handle(): int
    {
        $this->batchSize = max(1, (int) config('laraowl.records.prune.batch_size', 5000));
        $this->sleepMs = max(0, (int) config('laraowl.records.prune.sleep_ms', 50));
        $maxSeconds = (int) ($this->option('max-seconds') ?? config('laraowl.records.prune.max_seconds', 300));
        $this->deadline = microtime(true) + max(1, $maxSeconds);
        $this->outOfTime = false;

        $globalDays = (int) config('laraowl.records.retention_days', 7);
        $typeDays = array_filter(config('laraowl.records.type_retention_days', []), fn ($days) => (int) $days > 0);

        $deleted = $this->pruneGlobal($globalDays);
        $deleted += $this->pruneProjects($globalDays, $typeDays);
        $deleted += $this->pruneUptimeChecks((int) config('laraowl.uptime_checks.retention_days', 30));

        $this->info(($this->option('dry-run') ? 'Dry run, would delete from ' : 'Deleted ').number_format($deleted).' rows'
            .($this->outOfTime ? ' (stopped at the time limit; the next run continues).' : '.'));

        return self::SUCCESS;
    }

    /**
     * The hard cap: everything older than `retention_days`, regardless of
     * project or type, deleted by primary key range.
     */
    protected function pruneGlobal(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        return $this->pruneByIdRange('records', 'created_at', now()->subDays($days));
    }

    /**
     * Projects whose own `retention_days` is shorter than the cap, and types
     * with a shorter `type_retention_days`, via the `(project_id, created_at)`
     * and `(project_id, type, created_at)` indexes.
     *
     * @param  array<string, int>  $typeDays
     */
    protected function pruneProjects(int $globalDays, array $typeDays): int
    {
        $deleted = 0;

        foreach (Project::query()->get(['id', 'slug', 'retention_days']) as $project) {
            $projectDays = $this->shortest([$globalDays, (int) $project->retention_days]);

            // The global pass already covers a project whose window is the cap.
            if ($projectDays !== null && ($globalDays <= 0 || $projectDays < $globalDays)) {
                $deleted += $this->pruneByIndex(
                    fn () => DB::table('records')->where('project_id', $project->id),
                    now()->subDays($projectDays),
                    "{$project->slug}: older than {$projectDays}d",
                );
            }

            foreach ($typeDays as $type => $days) {
                $days = (int) $days;

                if ($projectDays !== null && $days >= $projectDays) {
                    continue;
                }

                $deleted += $this->pruneByIndex(
                    fn () => DB::table('records')->where('project_id', $project->id)->where('type', $type),
                    now()->subDays($days),
                    "{$project->slug}: {$type} older than {$days}d",
                );
            }
        }

        return $deleted;
    }

    protected function pruneUptimeChecks(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        return $this->pruneByIdRange('uptime_checks', 'checked_at', now()->subDays($days));
    }

    /**
     * Delete every row older than the cutoff from a table whose ids grow with
     * its timestamp, walking the primary key upwards from the smallest id.
     */
    protected function pruneByIdRange(string $table, string $timestampColumn, CarbonInterface $cutoff): int
    {
        $minId = DB::table($table)->min('id');

        if ($minId === null) {
            return 0;
        }

        $cutoffId = $this->firstIdAtOrAfter($table, $timestampColumn, $cutoff, (int) $minId);

        $this->line("{$table}: ids below {$cutoffId} are older than {$cutoff->toDateTimeString()}");

        if ($this->option('dry-run')) {
            return max(0, $cutoffId - (int) $minId);
        }

        $deleted = 0;

        for ($from = (int) $minId; $from < $cutoffId; $from += $this->batchSize) {
            if ($this->timeIsUp()) {
                break;
            }

            $deleted += DB::table($table)
                ->where('id', '>=', $from)
                ->where('id', '<', min($from + $this->batchSize, $cutoffId))
                ->delete();

            $this->pause();
        }

        return $deleted;
    }

    /**
     * Binary search the primary key for the first row stamped at or after the
     * cutoff: ~30 single-row PK lookups for a table of a billion rows, with no
     * index on the timestamp needed. Assumes ids and timestamps grow together,
     * which holds for rows stamped with now() at insert.
     */
    protected function firstIdAtOrAfter(string $table, string $timestampColumn, CarbonInterface $cutoff, int $minId): int
    {
        $low = $minId;
        $high = (int) DB::table($table)->max('id') + 1;
        $cutoffString = $cutoff->toDateTimeString();

        while ($low < $high) {
            $mid = intdiv($low + $high, 2);

            $row = DB::table($table)
                ->where('id', '>=', $mid)
                ->orderBy('id')
                ->first(['id', $timestampColumn]);

            if ($row === null || (string) $row->{$timestampColumn} >= $cutoffString) {
                $high = $mid;
            } else {
                $low = $mid + 1;
            }
        }

        return $low;
    }

    /**
     * Select a batch of ids through the index, then delete them by primary
     * key, until none are left. Portable (no DELETE ... LIMIT) and each
     * delete locks only the rows it removes.
     *
     * @param  callable(): Builder  $scope
     */
    protected function pruneByIndex(callable $scope, CarbonInterface $cutoff, string $label): int
    {
        if ($this->option('dry-run')) {
            $count = $scope()->where('created_at', '<', $cutoff)->count();
            $this->line("records ({$label}): ".number_format($count));

            return $count;
        }

        $deleted = 0;

        while (! $this->timeIsUp()) {
            $ids = $scope()
                ->where('created_at', '<', $cutoff)
                ->limit($this->batchSize)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            $deleted += DB::table('records')->whereIn('id', $ids)->delete();

            $this->pause();
        }

        if ($deleted > 0) {
            $this->line("records ({$label}): ".number_format($deleted));
        }

        return $deleted;
    }

    /**
     * The shortest positive retention among the candidates, or null when
     * none of them limits retention.
     *
     * @param  list<int>  $days
     */
    protected function shortest(array $days): ?int
    {
        $positive = array_filter($days, fn (int $day) => $day > 0);

        return $positive === [] ? null : min($positive);
    }

    protected function timeIsUp(): bool
    {
        if (microtime(true) >= $this->deadline) {
            $this->outOfTime = true;
        }

        return $this->outOfTime;
    }

    protected function pause(): void
    {
        if ($this->sleepMs > 0) {
            usleep($this->sleepMs * 1000);
        }
    }
}
