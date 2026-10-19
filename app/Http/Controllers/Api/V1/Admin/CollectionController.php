<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\CatalogStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CollectionRequest;
use App\Http\Requests\Admin\ImageUploadRules;
use App\Http\Resources\Admin\AdminCollectionResource;
use App\Models\ProductCollection;
use App\Services\Catalog\CollectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class CollectionController extends Controller
{
    public function __construct(private CollectionService $collections) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', Rule::enum(CatalogStatus::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $collections = ProductCollection::query()
            ->withCount('products')
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where('name', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderBy('display_order')->orderBy('name')
            ->paginate((int) ($filters['per_page'] ?? 50))->withQueryString();

        return AdminCollectionResource::collection($collections);
    }

    public function store(CollectionRequest $request): JsonResponse
    {
        $collection = $this->collections->save(null, $request->validated(), $request->user());

        return $this->respond($collection, 201);
    }

    public function show(ProductCollection $collection): JsonResponse
    {
        return $this->respond($collection);
    }

    public function update(CollectionRequest $request, ProductCollection $collection): JsonResponse
    {
        return $this->respond($this->collections->save($collection, $request->validated(), $request->user()));
    }

    public function status(Request $request, ProductCollection $collection): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::enum(CatalogStatus::class)]]);

        return $this->respond($this->collections->setStatus($collection, CatalogStatus::from($data['status']), $request->user()));
    }

    public function uploadImage(Request $request, ProductCollection $collection, string $slot): JsonResponse
    {
        $data = $request->validate([
            'image' => ImageUploadRules::file(),
            'alt' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $this->collections->setImage($collection, $slot, $data['image'], $data['alt'] ?? null, $request->user());

        return $this->respond($collection);
    }

    public function removeImage(Request $request, ProductCollection $collection, string $slot): JsonResponse
    {
        return $this->respond($this->collections->removeImage($collection, $slot, $request->user()));
    }

    public function destroy(Request $request, ProductCollection $collection): Response
    {
        $this->collections->delete($collection, $request->user());

        return response()->noContent();
    }

    private function respond(ProductCollection $collection, int $status = 200): JsonResponse
    {
        $collection->loadCount('products');

        return (new AdminCollectionResource($collection))->response()->setStatusCode($status);
    }
}
