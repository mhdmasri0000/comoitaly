<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('admin_notifications')) {
            Schema::create('admin_notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type', 50);
                $table->string('severity', 20)->default('info'); // info|warning|critical
                $table->string('title');
                $table->text('body')->nullable();
                $table->json('data')->nullable();
                $table->string('ref_type', 30)->nullable(); // order|product|user
                $table->uuid('ref_id')->nullable();
                $table->boolean('is_read')->default(false);
                $table->timestamp('read_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();

                $table->index(['is_read', 'created_at']);
                $table->index(['ref_type', 'ref_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_notifications');
    }
};
