<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\AdminNotifier;
use Illuminate\Console\Command;

class ScanStockAlerts extends Command
{
    protected $signature = 'alerts:scan-stock';

    protected $description = 'Generate low-stock and out-of-stock admin alerts for products';

    public function handle(): int
    {
        $threshold = AdminNotifier::lowStockThreshold();

        Product::query()
            ->where('quantity', '<=', $threshold)
            ->chunkById(100, function ($products) {
                foreach ($products as $product) {
                    AdminNotifier::syncStock($product);
                }
            });

        $this->info('Stock alerts scanned.');

        return self::SUCCESS;
    }
}
