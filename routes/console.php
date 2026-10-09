<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

\Illuminate\Support\Facades\Schedule::command('sand-casting:timeout-unrecorded-defects')
    ->dailyAt('23:30')
    ->name('sand-casting-auto-nihil-defects');
