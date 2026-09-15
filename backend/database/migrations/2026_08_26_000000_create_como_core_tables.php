<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 255);
            $table->string('image_cover')->nullable();
            $table->unsignedInteger('items_count')->default(0);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('category_id')->constrained('categories')->restrictOnDelete();
            $table->json('name');
            $table->decimal('price', 10, 2)->default(0);
            $table->unsignedInteger('quantity')->default(0);
            $table->float('rating')->default(0);
            $table->json('description')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_offer')->default(false);
            $table->timestamps();
        });

        foreach (['product_images', 'product_sizes', 'product_colors'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) use ($tableName) {
                $table->uuid('id')->primary();
                $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
                $table->timestamps();

                if ($tableName === 'product_images') {
                    $table->string('image_url');
                    $table->boolean('is_primary')->default(false);
                } elseif ($tableName === 'product_sizes') {
                    $table->string('size_label', 100);
                } else {
                    $table->string('color_name', 100);
                    $table->string('color_hex', 20)->nullable();
                }
            });
        }

    }

    public function down(): void
    {
        Schema::dropIfExists('product_colors');
        Schema::dropIfExists('product_sizes');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }
};
