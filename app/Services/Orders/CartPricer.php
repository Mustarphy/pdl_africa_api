<?php

namespace App\Services\Orders;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Catalog\CatalogImageStore;
use Illuminate\Support\Collection;

/**
 * Prices a cart from the database only. The browser sends product ids,
 * variant ids and quantities; names, prices and availability always come
 * from published catalogue records, never from the request.
 */
class CartPricer
{
    public function __construct(private readonly CatalogImageStore $images) {}

    /**
     * @param  list<array{product_id: int, variant_id: ?int, quantity: int}>  $items
     * @return array{lines: list<array<string, mixed>>, subtotal: int, currency: string, issues: list<array<string, mixed>>}
     */
    public function price(array $items): array
    {
        $products = Product::published()
            ->whereIn('id', array_column($items, 'product_id'))
            ->with(['variants', 'primaryImage'])
            ->get()
            ->keyBy('id');

        $lines = [];
        $issues = [];
        $subtotal = 0;

        foreach ($items as $index => $item) {
            $product = $products->get($item['product_id']);
            [$line, $issue] = $this->priceLine($index, $product, $item);
            if ($issue) {
                $issues[] = $issue;
            }
            if ($line) {
                $lines[] = $line;
                $subtotal += $line['line_total'];
            }
        }

        // The same variant listed twice must not bypass the stock check.
        foreach ($this->overStocked($lines) as $issue) {
            $issues[] = $issue;
        }

        return [
            'lines' => $lines,
            'subtotal' => $subtotal,
            'currency' => config('catalog.currency'),
            'issues' => $issues,
        ];
    }

    /** @return array{0: ?array<string, mixed>, 1: ?array<string, mixed>} */
    private function priceLine(int $index, ?Product $product, array $item): array
    {
        $issue = fn (string $code, string $message, array $extra = []) => [
            'index' => $index, 'product_id' => $item['product_id'], 'variant_id' => $item['variant_id'],
            'code' => $code, 'message' => $message, ...$extra,
        ];

        if (! $product) {
            return [null, $issue('unavailable', 'This piece is no longer available.')];
        }

        $activeVariants = $product->variants->where('is_active', true);
        $variant = null;
        if ($item['variant_id'] !== null) {
            $variant = $activeVariants->firstWhere('id', $item['variant_id']);
            if (! $variant) {
                return [null, $issue('unavailable', "The selected option of {$product->name} is no longer available.")];
            }
        } elseif ($activeVariants->isNotEmpty()) {
            return [null, $issue('variant_required', "Please choose a size or colour for {$product->name}.")];
        }

        $unitPrice = $variant?->price ?? $product->price;
        if ($unitPrice === null) {
            return [null, $issue('price_on_request', "{$product->name} is priced on request. Please order it by WhatsApp.")];
        }
        if ($variant && ! $variant->isPurchasable()) {
            return [null, $issue('out_of_stock', "{$product->name} ({$this->variantLabel($variant)}) is out of stock.")];
        }

        $quantity = (int) $item['quantity'];
        $image = $product->primaryImage ? $this->images->urls($product->primaryImage->descriptor()) : null;

        return [[
            'index' => $index,
            'product_id' => $product->id,
            'variant_id' => $variant?->id,
            'slug' => $product->slug,
            'name' => $product->name,
            'sku' => $variant?->sku ?? $product->sku,
            'size' => $variant?->size,
            'color' => $variant?->color,
            'image_url' => $image['srcset'][0]['url'] ?? null,
            'quantity' => $quantity,
            'unit_price' => (int) $unitPrice,
            'line_total' => (int) $unitPrice * $quantity,
            // Stock left for tracked variants (null = not tracked / made to order).
            'stock' => $this->trackedStock($variant),
        ], null];
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function overStocked(array $lines): Collection
    {
        return collect($lines)
            ->filter(fn ($line) => $line['stock'] !== null)
            ->groupBy('variant_id')
            ->filter(fn (Collection $group) => $group->sum('quantity') > $group->first()['stock'])
            ->map(fn (Collection $group) => [
                'index' => $group->first()['index'],
                'product_id' => $group->first()['product_id'],
                'variant_id' => $group->first()['variant_id'],
                'code' => 'insufficient_stock',
                'message' => $group->first()['stock'] === 1
                    ? "Only 1 of {$group->first()['name']} is left."
                    : "Only {$group->first()['stock']} of {$group->first()['name']} are left.",
                'available' => $group->first()['stock'],
            ])
            ->values();
    }

    private function trackedStock(?ProductVariant $variant): ?int
    {
        if (! $variant || $variant->stock_quantity === null) {
            return null;
        }

        return $variant->availability->value === 'made_to_order' ? null : $variant->stock_quantity;
    }

    private function variantLabel(ProductVariant $variant): string
    {
        return implode(', ', array_filter([$variant->size, $variant->color])) ?: $variant->sku;
    }
}
