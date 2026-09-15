<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hero_banners', function (Blueprint $table) {
            if (! Schema::hasColumn('hero_banners', 'type')) {
                $table->string('type', 20)->default('banner')->after('id')->index();
            }
        });

        DB::table('hero_banners')->whereNull('type')->orWhere('type', '')->update(['type' => 'banner']);
    }

    public function down(): void
    {
        Schema::table('hero_banners', function (Blueprint $table) {
            if (Schema::hasColumn('hero_banners', 'type')) {
                $table->dropColumn('type');
            }
        });
    }
};
