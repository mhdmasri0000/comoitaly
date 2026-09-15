<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'delivery_method')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('delivery_method', 20)->default('delivery')->after('delivery_address');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'delivery_method')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('delivery_method');
            });
        }
    }
};
