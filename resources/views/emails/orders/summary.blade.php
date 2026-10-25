@php($money = fn ($amount) => \App\Support\Money::format((int) $amount, $order['currency']))
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:8px 0 24px;">
  @foreach ($order['items'] as $item)
    <tr>
      <td style="padding:12px 0;border-bottom:1px solid #e6e6e6;font-size:14px;">
        <strong style="font-weight:600;">{{ $item['name'] }}</strong>
        @if ($item['size'] || $item['color'])
          <br><span style="color:#5f5f63;font-size:13px;">{{ implode(' · ', array_filter([$item['size'] ? 'Size '.$item['size'] : null, $item['color']])) }}</span>
        @endif
        <br><span style="color:#5f5f63;font-size:13px;">Qty {{ $item['quantity'] }} × {{ $money($item['unit_price']) }}</span>
      </td>
      <td align="right" style="padding:12px 0;border-bottom:1px solid #e6e6e6;font-size:14px;white-space:nowrap;vertical-align:top;">{{ $money($item['line_total']) }}</td>
    </tr>
  @endforeach
  <tr>
    <td style="padding:12px 0 4px;font-size:14px;color:#5f5f63;">Subtotal</td>
    <td align="right" style="padding:12px 0 4px;font-size:14px;">{{ $money($order['subtotal']) }}</td>
  </tr>
  <tr>
    <td style="padding:4px 0;font-size:14px;color:#5f5f63;">Delivery{{ $order['shipping_label'] ? ' ('.$order['shipping_label'].')' : '' }}</td>
    <td align="right" style="padding:4px 0;font-size:14px;">{{ ($order['shipping_on_delivery'] ?? false) ? 'Paid to the rider on delivery' : $money($order['shipping_fee']) }}</td>
  </tr>
  <tr>
    <td style="padding:12px 0 0;font-size:16px;font-weight:600;border-top:1px solid #0b0b0d;">{{ ($order['shipping_on_delivery'] ?? false) ? 'Total paid' : 'Total' }}</td>
    <td align="right" style="padding:12px 0 0;font-size:16px;font-weight:600;border-top:1px solid #0b0b0d;">{{ $money($order['total']) }}</td>
  </tr>
</table>
@if ($order['delivery'])
  <p style="margin:0 0 4px;font-size:12px;font-weight:600;letter-spacing:0.12em;text-transform:uppercase;">Delivering to</p>
  <p style="margin:0 0 16px;font-size:14px;color:#0b0b0d;">
    {{ $order['delivery']['name'] }}<br>
    {{ $order['delivery']['line1'] }}@if ($order['delivery']['line2'])<br>{{ $order['delivery']['line2'] }}@endif<br>
    {{ implode(', ', array_filter([$order['delivery']['city'], $order['delivery']['state'], $order['delivery']['postal_code']])) }}<br>
    {{ $order['delivery']['country_name'] }}
  </p>
@endif
