<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->index('category');
            $table->index('created_at');          // for latest()
            $table->index(['category', 'created_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->index(['user_id', 'created_at']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->index('order_id');
            $table->index('product_id');
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->index(['user_id', 'product_id']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['category']);
            $table->dropIndex(['created_at']);
            $table->dropIndex(['category', 'created_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'created_at']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['order_id']);
            $table->dropIndex(['product_id']);
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'product_id']);
            $table->dropIndex(['product_id']);
        });
    }
};