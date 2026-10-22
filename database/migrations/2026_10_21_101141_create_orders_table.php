<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('customer_name');
            $table->string('customer_email');
            $table->string('customer_phone', 30)->nullable();
            $table->text('shipping_address')->nullable();
            $table->bigInteger('amount');
            $table->char('currency', 3)->default('NGN');
            $table->enum('status', ['pending', 'paid', 'failed'])->default('pending');
            $table->json('items');
            $table->json('paystack_data')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
