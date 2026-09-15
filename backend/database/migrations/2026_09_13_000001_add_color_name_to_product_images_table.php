<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_images') && ! Schema::hasColumn('product_images', 'color_name')) {
            Schema::table('product_images', function (Blueprint $table) {
                $table->string('color_name', 100)->nullable()->after('image_url');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('product_images') && Schema::hasColumn('product_images', 'color_name')) {
            Schema::table('product_images', function (Blueprint $table) {
                $table->dropColumn('color_name');
            });
        }
    }
};
