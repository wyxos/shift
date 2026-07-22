<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('attachments:clean-temp')
    ->daily()
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(60)
    ->onOneServer();
