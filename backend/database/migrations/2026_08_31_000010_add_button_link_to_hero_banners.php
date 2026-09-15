<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hero_banners', function (Blueprint $table) {
            if (! Schema::hasColumn('hero_banners', 'button_link')) {
                $table->string('button_link', 500)->nullable()->after('button_text');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hero_banners', function (Blueprint $table) {
            if (Schema::hasColumn('hero_banners', 'button_link')) {
                $table->dropColumn('button_link');
            }
        });
    }
};
