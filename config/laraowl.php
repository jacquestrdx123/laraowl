<?php

use App\Support\TypeMap;

return [

    /*
    |--------------------------------------------------------------------------
    | Repository
    |--------------------------------------------------------------------------
    |
    | The GitHub repository new releases are checked against. Forks that cut
    | their own releases should point this at their own repository.
    |
    */

    'repository' => env('LARAOWL_REPOSITORY', 'laraowl/laraowl'),

    /*
    |--------------------------------------------------------------------------
    | Update Checks
    |--------------------------------------------------------------------------
    |
    | LaraOwl periodically asks the GitHub releases API whether a newer version
    | has been published, and shows a banner to team owners when there is one.
    | Disable this to stop the instance from making outbound requests.
    |
    */

    'update_check' => [
        'enabled' => env('LARAOWL_UPDATE_CHECK', true),
        'cache_ttl' => (int) env('LARAOWL_UPDATE_CHECK_TTL', 21600),
        'timeout' => (int) env('LARAOWL_UPDATE_CHECK_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Update Binaries
    |--------------------------------------------------------------------------
    |
    | Executables the `laraowl:update` command shells out to. Override these
    | when they are not resolvable on the PATH of the user running the update,
    | for example "/usr/local/bin/composer".
    |
    */

    'binaries' => [
        'git' => env('LARAOWL_GIT_BINARY', 'git'),
        'composer' => env('LARAOWL_COMPOSER_BINARY', 'composer'),
        'npm' => env('LARAOWL_NPM_BINARY', 'npm'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Raw Record Retention
    |--------------------------------------------------------------------------
    |
    | Raw records are the bulk of the database: one row per query, request,
    | job and cache event a client reports. The dashboard reads the rollups,
    | which are written at ingest and kept for each project's
    | `rollup_retention_days`, so raw records only need to live long enough
    | to drill into recent detail.
    |
    | `retention_days` is a hard cap applied to every project, on top of each
    | project's own `retention_days` (the shorter of the two wins; 0 disables
    | the cap). `type_retention_days` shortens it further for noisy types, as
    | a comma separated list of `type:days` pairs, e.g. "query:1,cache-event:2".
    |
    | `laraowl:records:prune` deletes in batches of `batch_size` rows, sleeping
    | `sleep_ms` between batches so it never holds long locks, and stops after
    | `max_seconds` (the next scheduled run picks up where it left off).
    |
    */

    'records' => [
        'retention_days' => (int) env('LARAOWL_RECORDS_RETENTION_DAYS', 7),

        'type_retention_days' => array_map('intval', TypeMap::parse(env('LARAOWL_RECORDS_TYPE_RETENTION', 'query:1'))),

        'prune' => [
            'batch_size' => (int) env('LARAOWL_RECORDS_PRUNE_BATCH', 5000),
            'sleep_ms' => (int) env('LARAOWL_RECORDS_PRUNE_SLEEP_MS', 50),
            'max_seconds' => (int) env('LARAOWL_RECORDS_PRUNE_MAX_SECONDS', 300),
        ],

        /*
        | Raw record sampling. The fraction (0..1) of each type's raw records
        | that are stored, as `type:rate` pairs, e.g. "query:0.1". Rollups still
        | count every record, so dashboard totals, averages and latency
        | histograms stay exact; only the drill-down rows are sampled.
        |
        | Sampling is keyed on the trace id when there is one, so a sampled
        | request keeps all of its queries. Records slower than
        | `keep_slower_than_ms`, failed records and records that breach a
        | threshold are always kept, and the types in `never_sample`
        | (exceptions, logs, requests, security events) are never dropped.
        | A project can override a type's rate in its settings under
        | `record_sample_rates`, e.g. {"query": 1}.
        */
        'sampling' => [
            'rates' => array_map('floatval', TypeMap::parse(env('LARAOWL_RECORDS_SAMPLE_RATES', 'query:0.1'))),
            'keep_slower_than_ms' => (float) env('LARAOWL_RECORDS_SAMPLE_KEEP_SLOWER_THAN_MS', 100),
            'never_sample' => ['exception', 'log', 'request', 'security-audit', 'auth-event', 'heartbeat'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Uptime Check Retention
    |--------------------------------------------------------------------------
    |
    | Individual uptime check results, one per project per check interval.
    | Kept for the longest dashboard period (30 days); 0 keeps them forever.
    |
    */

    'uptime_checks' => [
        'retention_days' => (int) env('LARAOWL_UPTIME_CHECKS_RETENTION_DAYS', 30),
    ],

];
