<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('offers')) {
            Schema::create('offers', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->json('title')->nullable();
                $table->json('subtitle')->nullable();
                $table->json('heading')->nullable();
                $table->json('button_text')->nullable();
                $table->string('image_cover')->nullable();
                $table->string('button_link', 500)->nullable();
                $table->unsignedTinyInteger('discount_percent')->default(0);
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('display_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('offer_product')) {
            Schema::create('offer_product', function (Blueprint $table) {
                $table->uuid('offer_id');
                $table->uuid('product_id');
                $table->unsignedTinyInteger('discount_percent')->nullable();
                $table->timestamps();

                $table->primary(['offer_id', 'product_id']);
                $table->foreign('offer_id')->references('id')->on('offers')->cascadeOnDelete();
                $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_product');
        Schema::dropIfExists('offers');
    }
};
