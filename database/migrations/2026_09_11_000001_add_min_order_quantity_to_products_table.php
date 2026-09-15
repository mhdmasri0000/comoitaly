<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'min_order_quantity')) {
            Schema::table('products', function (Blueprint $table) {
                $table->unsignedInteger('min_order_quantity')->default(1)->after('quantity');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'min_order_quantity')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('min_order_quantity');
            });
        }
    }
};
