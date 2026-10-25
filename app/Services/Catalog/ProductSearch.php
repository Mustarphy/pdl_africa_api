<?php

namespace App\Services\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductCollection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Public catalog listing, filtering, sorting, faceting and search.
 *
 * Every query starts from published products; only published collections
 * and categories are ever joined or exposed. Search is database-backed
 * (LIKE over the pre-normalised products.search_text column) with weighted
 * relevance. It can be swapped for a dedicated engine (e.g. Laravel Scout)
 * behind this class without changing controllers or the API contract.
 *
 * @phpstan-type Filters array{q?: string, collection?: list<string>, category?: list<string>, occasion?: list<string>, garment?: list<string>, size?: list<string>, color?: list<string>, min_price?: int, max_price?: int, featured?: bool, sort?: string}
 */
class ProductSearch
{
    public const SORTS = ['relevance', 'featured', 'newest', 'name', 'price_asc', 'price_desc'];

    private const MAX_TOKENS = 6;

    /** Lowest active price: product price, else cheapest active variant. */
    private const PRICE_SQL = '(COALESCE(products.price, (SELECT MIN(pv.price) FROM product_variants pv WHERE pv.product_id = products.id AND pv.is_active = 1 AND pv.price IS NOT NULL)))';

    /** @return list<string> */
    public static function tokens(?string $query): array
    {
        $normalized = SearchTextBuilder::normalize(mb_substr((string) $query, 0, 100));
        $tokens = array_values(array_unique(array_filter(explode(' ', $normalized))));
        // Simple plural stemming: "agbadas" -> "agbada" (LIKE also matches the plural).
        $tokens = array_map(fn ($t) => strlen($t) > 4 ? (string) preg_replace('/(es|s)$/', '', $t) : $t, $tokens);

        return array_slice(array_values(array_unique($tokens)), 0, self::MAX_TOKENS);
    }

    /**
     * @param  Filters  $filters
     * @return Builder<Product>
     */
    public function filtered(array $filters, ?string $exceptFacet = null): Builder
    {
        $query = Product::query()->published();

        foreach (self::tokens($filters['q'] ?? null) as $token) {
            $query->where('products.search_text', 'like', '%'.$token.'%');
        }
        if ($exceptFacet !== 'collection' && ! empty($filters['collection'])) {
            $query->whereHas('collections', fn ($c) => $c->published()->whereIn('slug', $filters['collection']));
        }
        if (! empty($filters['occasion'])) {
            $query->whereHas('occasions', fn ($o) => $o->published()->whereIn('slug', $filters['occasion']));
        }
        if ($exceptFacet !== 'category' && ! empty($filters['category'])) {
            $query->whereHas('category', fn ($c) => $c->published()->whereIn('slug', $filters['category']));
        }
        if ($exceptFacet !== 'garment' && ! empty($filters['garment'])) {
            $query->whereIn('products.garment_type', $filters['garment']);
        }
        foreach (['size', 'color'] as $field) {
            if ($exceptFacet !== $field && ! empty($filters[$field])) {
                $query->whereHas('variants', fn ($v) => $v->where('is_active', true)->whereIn($field, $filters[$field]));
            }
        }
        if (isset($filters['min_price'])) {
            $query->whereRaw(self::PRICE_SQL.' >= ?', [$filters['min_price']]);
        }
        if (isset($filters['max_price'])) {
            $query->whereRaw(self::PRICE_SQL.' <= ?', [$filters['max_price']]);
        }
        if (! empty($filters['featured'])) {
            $query->where('products.is_featured', true);
        }

        return $query;
    }

    /** @param  Filters  $filters */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->filtered($filters)->select('products.*');
        $this->applySort($query, $filters);

        return $this->withListRelations($query)->paginate($perPage)->withQueryString();
    }

    /** @return EloquentCollection<int, Product> */
    public function suggestProducts(string $q, int $limit): EloquentCollection
    {
        $query = $this->filtered(['q' => $q])->select('products.*');
        $this->applySort($query, ['q' => $q, 'sort' => 'relevance']);

        return $this->withListRelations($query)->limit($limit)->get();
    }

    /** @return EloquentCollection<int, ProductCollection> */
    public function suggestCollections(string $q, int $limit): EloquentCollection
    {
        $query = ProductCollection::published()->orderBy('display_order');
        foreach (self::tokens($q) as $token) {
            $query->where('name', 'like', '%'.$token.'%');
        }

        return self::tokens($q) ? $query->limit($limit)->get() : new EloquentCollection;
    }

    /**
     * Counts per value for each filter, each computed with all *other*
     * filters applied (values within a filter combine with OR).
     *
     * @param  Filters  $filters
     * @return list<array{key: string, label: string, values: list<array{value: string, label: string, count: int, selected: bool}>}>
     */
    public function facets(array $filters): array
    {
        $ids = fn (string $except) => $this->filtered($filters, $except)->select('products.id');
        $facet = function (string $key, string $label, iterable $rows) use ($filters) {
            $values = [];
            foreach ($rows as $row) {
                if ((int) $row->count > 0) {
                    $values[] = [
                        'value' => (string) $row->value,
                        'label' => (string) ($row->label ?? $row->value),
                        'count' => (int) $row->count,
                        'selected' => in_array((string) $row->value, $filters[$key] ?? [], true),
                    ];
                }
            }

            return ['key' => $key, 'label' => $label, 'values' => $values];
        };

        $collections = ProductCollection::published()->orderBy('display_order')->orderBy('name')
            ->select(['id', 'slug as value', 'name as label'])
            ->withCount(['products as count' => fn ($q) => $q->whereIn('products.id', $ids('collection'))])
            ->get();
        $categories = Category::published()->orderBy('display_order')->orderBy('name')
            ->select(['id', 'slug as value', 'name as label'])
            ->withCount(['products as count' => fn ($q) => $q->whereIn('products.id', $ids('category'))])
            ->get();
        $variantFacet = fn (string $field) => DB::table('product_variants')
            ->where('is_active', true)->where($field, '!=', '')
            ->whereIn('product_id', $ids($field))
            ->groupBy($field)->orderBy($field)
            ->selectRaw("{$field} as value, COUNT(DISTINCT product_id) as count")->get();
        $garments = $ids('garment')->getQuery()->cloneWithout(['columns'])
            ->whereNotNull('garment_type')->groupBy('garment_type')->orderBy('garment_type')
            ->selectRaw('garment_type as value, COUNT(*) as count')->get();

        return [
            $facet('collection', 'Collection', $collections),
            $facet('category', 'Category', $categories),
            $facet('garment', 'Garment', $garments),
            $facet('size', 'Size', $variantFacet('size')),
            $facet('color', 'Color', $variantFacet('color')),
        ];
    }

    /** @param  Filters  $filters */
    public function priceRange(array $filters): array
    {
        $row = $this->filtered($filters)->toBase()
            ->selectRaw('MIN('.self::PRICE_SQL.') as min, MAX('.self::PRICE_SQL.') as max')->first();

        return ['min' => $row?->min !== null ? (int) $row->min : null, 'max' => $row?->max !== null ? (int) $row->max : null];
    }

    /**
     * @param  Builder<Product>  $query
     * @param  Filters  $filters
     */
    private function applySort(Builder $query, array $filters): void
    {
        $tokens = self::tokens($filters['q'] ?? null);
        $sort = $filters['sort'] ?? ($tokens ? 'relevance' : 'featured');

        if ($sort === 'relevance' && $tokens) {
            $phrase = implode(' ', $tokens);
            $raw = mb_strtolower(trim((string) $filters['q']));
            $parts = [
                'CASE WHEN LOWER(products.sku) = ? THEN 120 ELSE 0 END',
                'CASE WHEN LOWER(products.name) = ? THEN 100 ELSE 0 END',
                'CASE WHEN LOWER(products.name) LIKE ? THEN 30 ELSE 0 END',
            ];
            $bindings = [$raw, $raw, $phrase.'%'];
            foreach ($tokens as $token) {
                $parts[] = 'CASE WHEN LOWER(products.name) LIKE ? THEN 10 ELSE 0 END';
                $bindings[] = '%'.$token.'%';
            }
            $query->selectRaw('('.implode(' + ', $parts).') as relevance', $bindings)
                ->orderByDesc('relevance');
        }

        match ($sort) {
            'newest' => $query->orderByDesc('products.published_at'),
            'name' => $query->orderBy('products.name'),
            'price_asc' => $query->orderByRaw(self::PRICE_SQL.' IS NULL')->orderByRaw(self::PRICE_SQL.' ASC'),
            'price_desc' => $query->orderByRaw(self::PRICE_SQL.' IS NULL')->orderByRaw(self::PRICE_SQL.' DESC'),
            default => $query->orderByDesc('products.is_featured'),
        };
        // Deterministic tie-breakers for stable pagination.
        $query->orderBy('products.display_order')->orderByDesc('products.id');
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    private function withListRelations(Builder $query): Builder
    {
        return $query->with([
            'category' => fn ($q) => $q->published(),
            'collections' => fn ($q) => $q->published()->orderBy('display_order'),
            'variants' => fn ($q) => $q->where('is_active', true),
            'images' => fn ($q) => $q->limit(2),
        ]);
    }
}
