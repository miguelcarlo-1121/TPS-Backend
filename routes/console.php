<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Kusang i-mark na completed ang approved appointments na tapos na ang oras (Asia/Manila).
Schedule::command('appointments:complete-ended')
    ->everyMinute()
    ->timezone('Asia/Manila');