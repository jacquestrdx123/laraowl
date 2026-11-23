<?php

use App\Models\Issue;
use App\Models\Project;
use App\Models\Record;
use App\Models\RecordRollup;
use App\Models\Threshold;
use App\Services\IngestService;
use App\Support\TypeMap;

beforeEach(function () {
    config([
        'laraowl.records.sampling.rates' => ['query' => 0.0],
        'laraowl.records.sampling.keep_slower_than_ms' => 100,
    ]);
});

test('sampled-out records are still counted in the rollups', function () {
    $project = Project::factory()->create();

    app(IngestService::class)->ingest($project, [
        ['t' => 'query', 'sql' => 'select 1', 'duration' => 500],
        ['t' => 'query', 'sql' => 'select 2', 'duration' => 700],
        ['t' => 'request', 'status_code' => 200, 'duration' => 1000],
    ]);

    $queryRollup = RecordRollup::where('project_id', $project->id)->where('type', 'query')->first();

    expect(Record::where('type', 'query')->count())->toBe(0)
        ->and(Record::where('type', 'request')->count())->toBe(1)
        ->and($queryRollup->count)->toBe(2)
        ->and((float) $queryRollup->sum_duration)->toBe(1200.0);
});

test('slow records are kept even when their type is sampled out', function () {
    $project = Project::factory()->create();

    app(IngestService::class)->ingest($project, [
        ['t' => 'query', 'sql' => 'fast', 'duration' => 500],
        ['t' => 'query', 'sql' => 'slow', 'duration' => 150_000],
    ]);

    expect(Record::where('type', 'query')->pluck('payload')->pluck('sql')->all())->toBe(['slow']);
});

test('errors are never sampled out, whatever the configured rate', function () {
    config(['laraowl.records.sampling.rates' => ['exception' => 0.0, 'log' => 0.0, 'outgoing-request' => 0.0]]);
    $project = Project::factory()->create();

    app(IngestService::class)->ingest($project, [
        ['t' => 'exception', 'class' => 'E', 'message' => 'boom'],
        ['t' => 'log', 'level' => 'error', 'message' => 'oops'],
        ['t' => 'outgoing-request', 'status_code' => 503, 'duration' => 10],
        ['t' => 'outgoing-request', 'status_code' => 200, 'duration' => 10],
    ]);

    expect(Record::where('type', 'exception')->count())->toBe(1)
        ->and(Record::where('type', 'log')->count())->toBe(1)
        ->and(Record::where('type', 'outgoing-request')->pluck('payload')->pluck('status_code')->all())->toBe([503]);
});

test('a record that breaches a threshold is stored as evidence for its issue', function () {
    config(['laraowl.records.sampling.keep_slower_than_ms' => 0]);
    $project = Project::factory()->create();
    Threshold::create([
        'project_id' => $project->id,
        'type' => 'query',
        'key' => 'select * from users',
        'value' => 5,
        'is_enabled' => true,
    ]);

    app(IngestService::class)->ingest($project, [
        ['t' => 'query', 'sql' => 'select * from users', 'duration' => 10_000],
    ]);

    $issue = Issue::where('project_id', $project->id)->first();

    expect($issue)->not->toBeNull()
        ->and(Record::where('issue_id', $issue->id)->count())->toBe(1);
});

test('sampling keeps or drops a whole trace together', function () {
    config(['laraowl.records.sampling.rates' => ['query' => 0.5]]);
    $project = Project::factory()->create();

    $traces = collect(range(1, 40))->map(fn ($i) => "trace-{$i}");

    app(IngestService::class)->ingest($project, $traces->flatMap(fn ($trace) => [
        ['t' => 'query', 'sql' => 'a', 'duration' => 10, 'trace_id' => $trace],
        ['t' => 'query', 'sql' => 'b', 'duration' => 10, 'trace_id' => $trace],
    ])->all());

    $perTrace = Record::where('type', 'query')->get()->groupBy('trace_id')->map->count();

    // Every kept trace has both its queries, and the 50% rate keeps some but not all.
    expect($perTrace->unique()->values()->all())->toBe([2])
        ->and($perTrace->count())->toBeGreaterThan(0)->toBeLessThan(40);
});

test('a project can override the configured rate', function () {
    $project = Project::factory()->create(['settings' => ['record_sample_rates' => ['query' => 1]]]);

    app(IngestService::class)->ingest($project, [
        ['t' => 'query', 'sql' => 'select 1', 'duration' => 10],
    ]);

    expect(Record::where('type', 'query')->count())->toBe(1);
});

test('type maps parse from env strings and skip malformed pairs', function () {
    expect(TypeMap::parse('query:0.1, cache-event:0.5,bogus,log:,x:y'))
        ->toBe(['query' => '0.1', 'cache-event' => '0.5'])
        ->and(TypeMap::parse(''))->toBe([])
        ->and(TypeMap::parse(null))->toBe([]);
});
