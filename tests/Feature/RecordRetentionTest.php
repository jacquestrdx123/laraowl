<?php

use App\Models\Project;
use App\Models\Record;
use App\Models\RecordRollup;
use App\Models\UptimeCheck;
use App\Services\IngestService;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Prunable;

beforeEach(function () {
    config([
        'laraowl.records.retention_days' => 7,
        'laraowl.records.type_retention_days' => [],
        'laraowl.records.prune.sleep_ms' => 0,
        'laraowl.records.prune.batch_size' => 2,
    ]);
});

function recordAged(Project $project, int $days, string $type = 'request'): Record
{
    return Record::factory()->create([
        'project_id' => $project->id,
        'type' => $type,
        'created_at' => now()->subDays($days),
    ]);
}

test('records past the global retention window are pruned in batches', function () {
    $project = Project::factory()->create(['retention_days' => 30]);

    // Inserted oldest first, like ingest does, so ids grow with created_at.
    $stale = collect([20, 15, 10, 9, 8])->map(fn ($days) => recordAged($project, $days));
    $fresh = collect([6, 1, 0])->map(fn ($days) => recordAged($project, $days));

    $this->artisan('laraowl:records:prune')->assertExitCode(0);

    expect(Record::whereIn('id', $stale->pluck('id'))->count())->toBe(0)
        ->and(Record::whereIn('id', $fresh->pluck('id'))->count())->toBe(3);
});

test('a project with a shorter retention_days is pruned to its own window', function () {
    $short = Project::factory()->create(['retention_days' => 1]);
    $long = Project::factory()->create(['retention_days' => 30]);

    $prunedByShortWindow = recordAged($short, 5);
    $keptByLongWindow = recordAged($long, 5);
    $keptBySameWindow = recordAged($short, 0);

    $this->artisan('laraowl:records:prune')->assertExitCode(0);

    expect(Record::find($prunedByShortWindow->id))->toBeNull()
        ->and(Record::find($keptByLongWindow->id))->not->toBeNull()
        ->and(Record::find($keptBySameWindow->id))->not->toBeNull();
});

test('the global cap applies even to a project with retention disabled', function () {
    $project = Project::factory()->create(['retention_days' => 0]);

    $ancient = recordAged($project, 400);
    $recent = recordAged($project, 2);

    $this->artisan('laraowl:records:prune')->assertExitCode(0);

    expect(Record::find($ancient->id))->toBeNull()
        ->and(Record::find($recent->id))->not->toBeNull();
});

test('with the global cap disabled a project with retention disabled keeps everything', function () {
    config(['laraowl.records.retention_days' => 0]);
    $project = Project::factory()->create(['retention_days' => 0]);

    $ancient = recordAged($project, 400);

    $this->artisan('laraowl:records:prune')->assertExitCode(0);

    expect(Record::find($ancient->id))->not->toBeNull();
});

test('noisy types can be kept for less time than everything else', function () {
    config(['laraowl.records.type_retention_days' => ['query' => 1]]);
    $project = Project::factory()->create(['retention_days' => 7]);

    $oldQuery = recordAged($project, 3, 'query');
    $oldRequest = recordAged($project, 3, 'request');
    $newQuery = recordAged($project, 0, 'query');

    $this->artisan('laraowl:records:prune')->assertExitCode(0);

    expect(Record::find($oldQuery->id))->toBeNull()
        ->and(Record::find($oldRequest->id))->not->toBeNull()
        ->and(Record::find($newQuery->id))->not->toBeNull();
});

test('a dry run deletes nothing', function () {
    $project = Project::factory()->create();
    $stale = recordAged($project, 30);

    $this->artisan('laraowl:records:prune', ['--dry-run' => true])->assertExitCode(0);

    expect(Record::find($stale->id))->not->toBeNull();
});

test('pruning stops at the time limit and the next run carries on', function () {
    config(['laraowl.records.prune.batch_size' => 1, 'laraowl.records.prune.sleep_ms' => 1100]);
    $project = Project::factory()->create();
    collect([20, 19, 18])->each(fn ($days) => recordAged($project, $days));

    $this->artisan('laraowl:records:prune', ['--max-seconds' => 1])
        ->expectsOutputToContain('stopped at the time limit')
        ->assertExitCode(0);

    expect(Record::count())->toBeGreaterThan(0)->toBeLessThan(3);

    config(['laraowl.records.prune.sleep_ms' => 0]);
    $this->artisan('laraowl:records:prune')->assertExitCode(0);

    expect(Record::count())->toBe(0);
});

test('pruning raw records leaves the rollups alone', function () {
    $project = Project::factory()->create();

    $this->travelTo(now()->subDays(10));
    app(IngestService::class)->ingest($project, [['t' => 'request', 'status_code' => 200, 'duration' => 10]]);
    $this->travelBack();

    $this->artisan('laraowl:records:prune')->assertExitCode(0);

    expect(Record::where('project_id', $project->id)->count())->toBe(0)
        ->and((int) RecordRollup::where('project_id', $project->id)->sum('count'))->toBe(1);
});

test('uptime checks past their retention window are pruned', function () {
    config(['laraowl.uptime_checks.retention_days' => 30]);
    $project = Project::factory()->create();

    $old = UptimeCheck::create(['project_id' => $project->id, 'status' => 'up', 'checked_at' => now()->subDays(40)]);
    $new = UptimeCheck::create(['project_id' => $project->id, 'status' => 'up', 'checked_at' => now()->subDays(2)]);

    $this->artisan('laraowl:records:prune')->assertExitCode(0);

    expect(UptimeCheck::find($old->id))->toBeNull()
        ->and(UptimeCheck::find($new->id))->not->toBeNull();
});

test('records are no longer pruned by model:prune', function () {
    expect(class_uses_recursive(Record::class))
        ->not->toContain(MassPrunable::class)
        ->not->toContain(Prunable::class);
});

test('a rollup backfill does not reach back past raw retention', function () {
    config(['laraowl.records.retention_days' => 7]);
    $project = Project::factory()->create();

    $old = RecordRollup::create([
        'project_id' => $project->id,
        'type' => 'request',
        'bucket' => now()->subDays(30)->startOfMinute(),
        'count' => 42,
    ]);

    $this->artisan('laraowl:rollups:backfill')->assertExitCode(0);

    expect(RecordRollup::find($old->id)?->count)->toBe(42);
});
