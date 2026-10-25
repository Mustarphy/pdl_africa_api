<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\CatalogStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProductRequest;
use App\Http\Resources\Admin\AdminProductResource;
use App\Models\Product;
use App\Services\Catalog\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function __construct(private ProductService $products) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', Rule::enum(CatalogStatus::class)],
            'collection_id' => ['sometimes', 'nullable', 'integer'],
            'category_id' => ['sometimes', 'nullable', 'integer'],
            'missing_images' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', Rule::in(['updated', 'name', 'price', 'sku'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Product::query()
            ->with(['category', 'collections', 'variants', 'primaryImage'])
            ->withCount('images')
            ->when($filters['q'] ?? null, function ($q, string $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('sku', 'like', $like));
            })
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['collection_id'] ?? null, fn ($q, $id) => $q->whereHas('collections', fn ($c) => $c->whereKey($id)))
            ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->where('category_id', $id))
            ->when($request->boolean('missing_images'), fn ($q) => $q->whereDoesntHave('images'));

        $sort = $filters['sort'] ?? 'updated';
        $direction = $filters['direction'] ?? ($sort === 'updated' ? 'desc' : 'asc');
        $query->orderBy(match ($sort) {
            'name' => 'name',
            'price' => 'price',
            'sku' => 'sku',
            default => 'updated_at',
        }, $direction)->orderByDesc('id');

        return AdminProductResource::collection($query->paginate((int) ($filters['per_page'] ?? 20))->withQueryString());
    }

    public function store(ProductRequest $request): JsonResponse
    {
        $product = $this->products->save(null, $request->validated(), $request->user());

        return $this->detail($product)->response()->setStatusCode(201);
    }

    public function show(Product $product): AdminProductResource
    {
        return $this->detail($product);
    }

    public function update(ProductRequest $request, Product $product): AdminProductResource
    {
        return $this->detail($this->products->save($product, $request->validated(), $request->user()));
    }

    public function status(Request $request, Product $product): AdminProductResource
    {
        $data = $request->validate(['status' => ['required', Rule::enum(CatalogStatus::class)]]);

        return $this->detail($this->products->setStatus($product, CatalogStatus::from($data['status']), $request->user()));
    }

    public function destroy(Request $request, Product $product): Response
    {
        $this->products->delete($product, $request->user());

        return response()->noContent();
    }

    private function detail(Product $product): AdminProductResource
    {
        $product->unsetRelations()->load(['category', 'collections', 'occasions', 'variants', 'images', 'primaryImage']);

        return (new AdminProductResource($product))->detailed();
    }
}
