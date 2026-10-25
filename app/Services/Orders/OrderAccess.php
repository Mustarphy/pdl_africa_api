<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Services\Account\AccountLinks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Who may see an order: the signed-in customer who placed it, or a browser
 * session that was granted access, either by placing the order or by
 * opening a signed link emailed to the order's address. An order reference
 * (or reference + email) on its own never grants access.
 */
class OrderAccess
{
    private const SESSION_KEY = 'order_access';

    private const MAX_REMEMBERED = 20;

    public function __construct(private readonly AccountLinks $links) {}

    public function grant(Request $request, Order $order): void
    {
        if (! $request->hasSession()) {
            return;
        }
        $ids = array_values(array_diff((array) $request->session()->get(self::SESSION_KEY, []), [$order->id]));
        $ids[] = $order->id;
        $request->session()->put(self::SESSION_KEY, array_slice($ids, -self::MAX_REMEMBERED));
    }

    public function allows(Request $request, Order $order): bool
    {
        $user = $request->user();
        if ($user && $user->isCustomer() && $order->user_id === $user->id) {
            return true;
        }

        return $request->hasSession()
            && in_array($order->id, (array) $request->session()->get(self::SESSION_KEY, []), true);
    }

    /** Storefront link that grants access to this order for `$minutes`. */
    public function link(Order $order, int $minutes): string
    {
        $signed = URL::temporarySignedRoute('api.v1.orders.access', now()->addMinutes($minutes), [
            'reference' => $order->reference,
        ], absolute: false);
        parse_str((string) parse_url($signed, PHP_URL_QUERY), $query);

        return $this->links->frontend('/orders/'.rawurlencode($order->reference)).'?'.http_build_query($query);
    }

    /** @return AccountLinks::VALID|AccountLinks::EXPIRED|AccountLinks::INVALID */
    public function checkLink(Request $request): string
    {
        return $this->links->check($request);
    }
}
