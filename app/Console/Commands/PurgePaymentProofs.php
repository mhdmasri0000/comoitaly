<?php

namespace App\Console\Commands;

use App\Services\PaymentProofCleaner;
use Illuminate\Console\Command;

class PurgePaymentProofs extends Command
{
    protected $signature = 'orders:purge-payment-proofs {--days= : Override the retention window in days}';

    protected $description = 'Delete payment proof images for orders past the retention window';

    public function handle(PaymentProofCleaner $cleaner): int
    {
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : PaymentProofCleaner::retentionDays();

        $count = $cleaner->purgeOlderThan($days);

        $this->info("Purged {$count} payment proof(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
