<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ImageUploadRules;
use App\Http\Resources\Admin\AdminProductResource;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Catalog\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Image routes use scoped bindings: /products/{product}/images/{image}
 * only resolves images that belong to that product (404 otherwise).
 */
class ProductImageController extends Controller
{
    public function __construct(private ProductService $products) {}

    public function store(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:'.config('catalog.images.max_per_product')],
            'images.*' => ImageUploadRules::file(),
            'alt_texts' => ['sometimes', 'array'],
            'alt_texts.*' => ['nullable', 'string', 'max:255'],
        ]);

        $this->products->addImages($product, $data['images'], $data['alt_texts'] ?? [], $request->user());

        return $this->respond($product, 201);
    }

    public function update(Request $request, Product $product, ProductImage $image): JsonResponse
    {
        $data = $request->validate([
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_primary' => ['sometimes', 'boolean'],
            'product_variant_id' => ['sometimes', 'nullable', 'integer',
                Rule::exists('product_variants', 'id')->where('product_id', $product->id)],
        ]);
        if (array_key_exists('is_primary', $data)) {
            $data['is_primary'] = $request->boolean('is_primary');
        }

        $this->products->updateImage($image, $data, $request->user());

        return $this->respond($product);
    }

    public function reorder(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'image_ids' => ['required', 'array', 'max:'.config('catalog.images.max_per_product')],
            'image_ids.*' => ['integer', 'distinct'],
        ]);
        $this->products->reorderImages($product, $data['image_ids'], $request->user());

        return $this->respond($product);
    }

    public function destroy(Request $request, Product $product, ProductImage $image): JsonResponse
    {
        $this->products->deleteImage($image, $request->user());

        return $this->respond($product);
    }

    private function respond(Product $product, int $status = 200): JsonResponse
    {
        $product->unsetRelations()->load(['category', 'collections', 'occasions', 'variants', 'images', 'primaryImage']);

        return (new AdminProductResource($product))->detailed()->response()->setStatusCode($status);
    }
}
