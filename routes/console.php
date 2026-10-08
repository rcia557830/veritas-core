<?php

use App\Services\Notify;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('veritas:notify', function () {
    Notify::due();
    $this->info('Deadline notifications synchronized.');
})->purpose('Generate upcoming and overdue workspace notifications');
Schedule::command('veritas:notify')->dailyAt('07:00')->withoutOverlapping();
