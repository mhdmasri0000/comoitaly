<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('admin_notifications') || Schema::hasColumn('admin_notifications', 'user_id')) {
            return;
        }

        // Legacy rows were global (no owner); the inbox is now per-admin, so drop them.
        DB::table('admin_notifications')->delete();

        Schema::table('admin_notifications', function (Blueprint $table) {
            $table->uuid('user_id')->nullable()->after('id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'is_read']);
            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('admin_notifications') || ! Schema::hasColumn('admin_notifications', 'user_id')) {
            return;
        }

        Schema::table('admin_notifications', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropIndex(['user_id', 'is_read']);
            $table->dropIndex(['user_id', 'type']);
            $table->dropColumn('user_id');
        });
    }
};
