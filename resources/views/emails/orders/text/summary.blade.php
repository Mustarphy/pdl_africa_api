@php($money = fn ($amount) => \App\Support\Money::format((int) $amount, $order['currency']))
@foreach ($order['items'] as $item)
- {{ $item['name'] }}{{ ($item['size'] || $item['color']) ? ' ('.implode(', ', array_filter([$item['size'] ? 'Size '.$item['size'] : null, $item['color']])).')' : '' }} x{{ $item['quantity'] }}: {{ $money($item['line_total']) }}
@endforeach

Subtotal: {{ $money($order['subtotal']) }}
Delivery: {{ ($order['shipping_on_delivery'] ?? false) ? 'paid to the rider on delivery' : $money($order['shipping_fee']) }}
Total{{ ($order['shipping_on_delivery'] ?? false) ? ' paid' : '' }}: {{ $money($order['total']) }}
