<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'fcm_platform')) {
                $table->string('fcm_platform', 20)->nullable()->after('fcm_token');
            }
        });

        Schema::create('notification_broadcasts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('type', 50)->nullable();
            $table->string('platform', 30)->default('all'); // all|ios|android
            $table->string('audience', 30)->default('customers');
            $table->string('status', 30)->default('active');
            $table->unsignedInteger('recipients')->default(0);
            $table->unsignedInteger('push_sent')->default(0);
            $table->uuid('ref_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_broadcasts');

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'fcm_platform')) {
                $table->dropColumn('fcm_platform');
            }
        });
    }
};
