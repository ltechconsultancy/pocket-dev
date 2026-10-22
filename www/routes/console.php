<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Memory Schema Snapshots
|--------------------------------------------------------------------------
|
| Hourly snapshots of the memory schema for disaster recovery.
| Tiered retention: hourly (24h) → 4/day (7d) → 1/day (30d)
|
*/

Schedule::command('memory:snapshot create')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('memory:snapshot prune')
    ->daily()
    ->withoutOverlapping()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| Stale Conversation Cleanup
|--------------------------------------------------------------------------
|
| Cleanup conversations stuck in "processing" state (e.g., worker died).
| Runs every minute, marks stale conversations as failed after 5 min.
|
*/

Schedule::command('conversations:cleanup-stale')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| Conversation Embedding Backfill
|--------------------------------------------------------------------------
|
| Backfill turn numbers and embeddings for any conversations that may have
| been missed (e.g., due to embedding service downtime or job failure).
| New conversations are embedded automatically via GenerateConversationEmbeddings.
|
*/

Schedule::command('conversation:backfill-embeddings')
    ->daily()
    ->withoutOverlapping()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| Orphaned Process Cleanup
|--------------------------------------------------------------------------
|
| Kill long-running Node/Chrome processes that were started by AI sessions
| and left behind after the session ended (re-parented to PID 1).
| Runs every 30 minutes, only targets processes older than 30 min.
|
*/

Schedule::command('processes:cleanup-orphaned')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| Disk cleanup (StorageJanitor)
|--------------------------------------------------------------------------
|
| Daily: /tmp work dirs untouched for 7 days, PocketDev logs (conversation
| stream logs 14 d, request-flow 7 d), oversized package caches.
| Weekly: stopped containers (never pocket-dev-*) and unused images.
| Never: chat uploads, conversations, project folders, anything in use.
| The disk guard (warn/critical + restarting FATAL workers) is NOT scheduled
| here: the host's pd-disk-guard timer runs `artisan storage:guard`.
|
*/

Schedule::command('storage:cleanup')
    ->dailyAt('04:30')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('storage:cleanup --only=docker')
    ->weeklyOn(0, '05:00')
    ->withoutOverlapping()
    ->runInBackground();
