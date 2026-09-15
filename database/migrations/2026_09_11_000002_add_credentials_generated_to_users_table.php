<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'credentials_generated')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('credentials_generated')->default(false)->after('fcm_platform');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'credentials_generated')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('credentials_generated');
            });
        }
    }
};
