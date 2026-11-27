<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('nadi:refresh-quotes')
    ->dailyAt('17:30')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();

Schedule::command('nadi:refresh-analytics')
    ->dailyAt('17:45')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();

Schedule::command('nadi:sync-news')
    ->hourly()
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();
