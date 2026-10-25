<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Models\Order;
use App\Services\Orders\PaymentProcessor;
use Illuminate\Console\Command;

/**
 * Re-checks unpaid orders whose Paystack payment is still unresolved (for
 * example when a webhook was missed while the API was asleep). Safe to run
 * any time and as often as needed; nothing is scheduled automatically.
 */
class ReconcilePaymentsCommand extends Command
{
    protected $signature = 'orders:reconcile-payments {--days=7 : Only orders created within this many days}';

    protected $description = 'Verify unresolved Paystack payments for unpaid orders';

    public function handle(PaymentProcessor $payments): int
    {
        $orders = Order::where('status', OrderStatus::PendingPayment)
            ->where('created_at', '>=', now()->subDays((int) $this->option('days')))
            ->whereHas('paymentAttempts', fn ($q) => $q->whereIn('status', [PaymentAttemptStatus::Pending, PaymentAttemptStatus::Abandoned]))
            ->get();

        $paid = 0;
        foreach ($orders as $order) {
            if ($payments->reconcile($order, force: true)->isPaid()) {
                $paid++;
                $this->line("{$order->reference}: paid");
            }
        }
        $this->info("Checked {$orders->count()} order(s); {$paid} newly confirmed as paid.");

        return self::SUCCESS;
    }
}
