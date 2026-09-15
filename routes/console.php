<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('alerts:scan-stock')->hourly();

// Free disk space: drop payment-proof files that are no longer needed.
Schedule::command('orders:purge-payment-proofs')->daily();
