<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use Illuminate\Support\Facades\Schedule;

Schedule::command('projects:check-health')
    ->everyThirtySeconds()
    ->withoutOverlapping(5)
    ->runInBackground();
Schedule::command('model:prune')->daily();
// Raw records are pruned here rather than by model:prune: small batches by
// primary key, often enough that each run only has a few minutes of rows.
Schedule::command('laraowl:records:prune')
    ->everyTenMinutes()
    ->withoutOverlapping(30)
    ->runInBackground();
Schedule::command('queue:prune-failed', ['--hours' => 168])->daily();
Schedule::command('laraowl:update --check')->daily();
