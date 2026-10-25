<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\CatalogStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ImageUploadRules;
use App\Http\Requests\Admin\OccasionRequest;
use App\Http\Resources\Admin\AdminOccasionResource;
use App\Models\Occasion;
use App\Services\Catalog\OccasionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class OccasionController extends Controller
{
    public function __construct(private OccasionService $occasions) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::enum(CatalogStatus::class)],
        ]);

        $occasions = Occasion::query()
            ->withCount('products')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderBy('display_order')->orderBy('name')
            ->get();

        return AdminOccasionResource::collection($occasions);
    }

    public function store(OccasionRequest $request): JsonResponse
    {
        return $this->respond($this->occasions->save(null, $request->validated(), $request->user()), 201);
    }

    public function show(Occasion $occasion): JsonResponse
    {
        return $this->respond($occasion);
    }

    public function update(OccasionRequest $request, Occasion $occasion): JsonResponse
    {
        return $this->respond($this->occasions->save($occasion, $request->validated(), $request->user()));
    }

    public function status(Request $request, Occasion $occasion): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::enum(CatalogStatus::class)]]);

        return $this->respond($this->occasions->setStatus($occasion, CatalogStatus::from($data['status']), $request->user()));
    }

    public function products(Request $request, Occasion $occasion): JsonResponse
    {
        $data = $request->validate([
            'product_ids' => ['present', 'array', 'max:500'],
            'product_ids.*' => ['integer', 'distinct', Rule::exists('products', 'id')],
        ]);

        return $this->respond($this->occasions->syncProducts($occasion, $data['product_ids'], $request->user()));
    }

    public function uploadImage(Request $request, Occasion $occasion): JsonResponse
    {
        $data = $request->validate([
            'image' => ImageUploadRules::file(),
            'alt' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        return $this->respond($this->occasions->setImage($occasion, $data['image'], $data['alt'] ?? null, $request->user()));
    }

    public function removeImage(Request $request, Occasion $occasion): JsonResponse
    {
        return $this->respond($this->occasions->removeImage($occasion, $request->user()));
    }

    public function destroy(Request $request, Occasion $occasion): Response
    {
        $this->occasions->delete($occasion, $request->user());

        return response()->noContent();
    }

    private function respond(Occasion $occasion, int $status = 200): JsonResponse
    {
        $occasion->unsetRelations()->load(['products' => fn ($q) => $q->orderBy('occasion_product.position')])->loadCount('products');

        return (new AdminOccasionResource($occasion))->response()->setStatusCode($status);
    }
}
