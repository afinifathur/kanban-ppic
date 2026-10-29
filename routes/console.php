<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

\Illuminate\Support\Facades\Schedule::command('sand-casting:timeout-unrecorded-defects')
    ->hourly()
    ->name('sand-casting-auto-nihil-defects');

\Illuminate\Support\Facades\Schedule::command('sand-casting:capture-wip-snapshot')
    ->dailyAt('07:00')
    ->timezone('Asia/Jakarta')
    ->name('sand-casting-daily-wip-snapshot')
    ->withoutOverlapping();
