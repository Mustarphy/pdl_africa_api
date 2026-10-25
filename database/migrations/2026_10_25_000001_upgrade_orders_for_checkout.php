<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turns the first prototype `orders` table into the full order model.
 * Additive and data-preserving: existing rows keep their values; their old
 * single `status` (pending/paid/failed) becomes `payment_status`, and paid
 * rows become `confirmed` orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('payment_status', 20)->default('pending')->after('status')->index();
            $table->unsignedBigInteger('subtotal')->default(0)->after('customer_phone');
            $table->unsignedBigInteger('shipping_fee')->default(0)->after('subtotal');
            $table->unsignedBigInteger('discount')->default(0)->after('shipping_fee');

            // Delivery address (structured; `shipping_address` stays for old rows).
            $table->string('address_line1', 200)->nullable()->after('shipping_address');
            $table->string('address_line2', 200)->nullable()->after('address_line1');
            $table->string('city', 100)->nullable()->after('address_line2');
            $table->string('state', 100)->nullable()->after('city');
            $table->string('postal_code', 20)->nullable()->after('state');
            $table->char('country_code', 2)->nullable()->after('postal_code');
            $table->string('delivery_notes', 500)->nullable()->after('country_code');
            $table->string('shipping_zone', 40)->nullable()->after('delivery_notes');
            $table->string('shipping_label', 120)->nullable()->after('shipping_zone');
            $table->string('delivery_estimate', 120)->nullable()->after('shipping_label');

            // Fulfilment.
            $table->string('carrier', 120)->nullable();
            $table->string('tracking_number', 120)->nullable();
            $table->string('tracking_url', 500)->nullable();
            $table->text('fulfillment_notes')->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('stock_deducted_at')->nullable();
            // Set when a paid order could not take its stock (sold out meanwhile).
            $table->boolean('stock_issue')->default(false);
            $table->timestamp('confirmation_email_sent_at')->nullable();
            // Repeated checkout submissions with the same key return the same order.
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->timestamp('payment_checked_at')->nullable();

            $table->index('customer_email');
            $table->index('created_at');
        });

        // Old single status -> payment status, before `status` changes meaning.
        DB::table('orders')->update(['payment_status' => DB::raw('status')]);
        DB::table('orders')->update(['subtotal' => DB::raw('amount')]);
        DB::table('orders')->where('status', 'paid')->update(['paid_at' => DB::raw('updated_at')]);

        Schema::table('orders', function (Blueprint $table) {
            $table->string('status', 30)->default('pending_payment')->change();
            $table->json('items')->nullable()->change();
        });
        DB::table('orders')->where('payment_status', 'paid')->update(['status' => 'confirmed']);
        DB::table('orders')->where('payment_status', '!=', 'paid')->update(['status' => 'pending_payment']);
        Schema::table('orders', fn (Blueprint $table) => $table->index('status'));

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->after('order_id')->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
            $table->string('sku', 64)->nullable()->after('name');
            $table->string('image_url', 500)->nullable()->after('color');
            $table->unsignedBigInteger('line_total')->default(0)->after('unit_price');
        });

        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20)->default('paystack');
            $table->string('reference', 80)->unique();
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->string('status', 20)->default('pending')->index();
            // Paystack's own status and message (e.g. "Approved", "Declined").
            $table->string('provider_status', 30)->nullable();
            $table->string('gateway_response', 255)->nullable();
            $table->string('channel', 30)->nullable();
            $table->unsignedBigInteger('provider_transaction_id')->nullable();
            $table->string('authorization_url', 500)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20)->default('paystack');
            $table->string('event', 60);
            $table->string('reference', 80)->nullable()->index();
            $table->unsignedBigInteger('provider_transaction_id')->nullable();
            // Short outcome, e.g. "applied", "duplicate", "unknown_reference".
            $table->string('outcome', 40)->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event', 'provider_transaction_id']);
        });

        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            // system | customer | admin
            $table->string('actor_type', 20);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            // Internal note (administrators only).
            $table->string('note', 1000)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payment_attempts');

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_variant_id');
            $table->dropConstrainedForeignId('product_id');
            $table->dropColumn(['sku', 'image_url', 'line_total']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });
        DB::table('orders')->update(['status' => DB::raw('payment_status')]);
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('status', ['pending', 'paid', 'failed'])->default('pending')->change();
            $table->dropConstrainedForeignId('user_id');
            $table->dropIndex(['customer_email']);
            $table->dropIndex(['created_at']);
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn([
                'payment_status', 'subtotal', 'shipping_fee', 'discount',
                'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country_code',
                'delivery_notes', 'shipping_zone', 'shipping_label', 'delivery_estimate',
                'carrier', 'tracking_number', 'tracking_url', 'fulfillment_notes',
                'paid_at', 'dispatched_at', 'delivered_at', 'cancelled_at', 'stock_deducted_at',
                'stock_issue', 'confirmation_email_sent_at', 'idempotency_key', 'payment_checked_at',
            ]);
        });
    }
};
