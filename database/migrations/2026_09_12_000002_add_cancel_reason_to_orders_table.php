<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'cancel_reason')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('cancel_reason', 1000)->nullable()->after('note');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'cancel_reason')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('cancel_reason');
            });
        }
    }
};
