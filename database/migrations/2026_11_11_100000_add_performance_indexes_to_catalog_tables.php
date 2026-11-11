<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->index(['category', 'created_at'], 'products_category_created_at_index');
            $table->index('created_at', 'products_created_at_index');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['user_id', 'created_at'], 'orders_user_id_created_at_index');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->index(['order_id', 'product_id'], 'order_items_order_product_index');
        });

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->index(['user_id', 'product_id'], 'cart_items_user_product_index');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_category_created_at_index');
            $table->dropIndex('products_created_at_index');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_user_id_created_at_index');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropIndex('order_items_order_product_index');
        });

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropIndex('cart_items_user_product_index');
        });
    }
};
