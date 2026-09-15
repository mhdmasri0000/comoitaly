<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favorites', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->foreignUuid('user_id')->constrained()->cascadeOnDelete(); $table->foreignUuid('product_id')->constrained()->cascadeOnDelete(); $table->timestamps(); $table->unique(['user_id', 'product_id']);
        });
        Schema::create('cart_items', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->foreignUuid('user_id')->constrained()->cascadeOnDelete(); $table->foreignUuid('product_id')->constrained()->cascadeOnDelete(); $table->string('size')->nullable(); $table->string('color')->nullable(); $table->unsignedInteger('quantity')->default(1); $table->date('reserved_on')->nullable(); $table->date('reserved_until')->nullable(); $table->decimal('total_price', 10, 2)->default(0); $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->foreignUuid('user_id')->constrained()->cascadeOnDelete(); $table->string('delivery_address'); $table->text('note')->nullable(); $table->string('payment_type'); $table->string('payment_proof_image')->nullable(); $table->string('status')->default('pending'); $table->decimal('subtotal', 10, 2)->default(0); $table->decimal('delivery_fee', 10, 2)->default(0); $table->decimal('total', 10, 2)->default(0); $table->timestamps();
        });
        Schema::create('order_items', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->foreignUuid('order_id')->constrained()->cascadeOnDelete(); $table->foreignUuid('product_id')->constrained()->restrictOnDelete(); $table->string('product_name')->nullable(); $table->decimal('unit_price', 10, 2); $table->decimal('total_price', 10, 2)->default(0); $table->unsignedInteger('quantity'); $table->string('size')->nullable(); $table->string('color')->nullable(); $table->timestamps();
        });
        Schema::create('notifications', function (Blueprint $table) { $table->uuid('id')->primary(); $table->foreignUuid('user_id')->constrained()->cascadeOnDelete(); $table->string('title'); $table->text('body')->nullable(); $table->string('type')->nullable(); $table->uuid('ref_id')->nullable(); $table->boolean('is_read')->default(false); $table->timestamps(); });
        Schema::create('hero_banners', function (Blueprint $table) { $table->uuid('id')->primary(); $table->string('image_cover')->nullable(); $table->string('subtitle')->nullable(); $table->string('heading')->nullable(); $table->string('title')->nullable(); $table->string('button_text')->nullable(); $table->boolean('is_active')->default(true); $table->unsignedInteger('display_order')->default(0); $table->timestamps(); });
        Schema::create('settings', function (Blueprint $table) { $table->id(); $table->string('key')->unique(); $table->text('value')->nullable(); $table->timestamps(); });
        Schema::create('password_resets', function (Blueprint $table) { $table->uuid('id')->primary(); $table->foreignUuid('user_id')->constrained()->cascadeOnDelete(); $table->string('token')->unique(); $table->timestamp('expires_at'); $table->boolean('used')->default(false); $table->timestamps(); });
    }

    public function down(): void
    {
        foreach (['password_resets', 'settings', 'hero_banners', 'notifications', 'order_items', 'orders', 'cart_items', 'favorites'] as $table) Schema::dropIfExists($table);
    }
};