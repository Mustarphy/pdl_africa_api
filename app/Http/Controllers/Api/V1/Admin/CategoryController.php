<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CategoryRequest;
use App\Models\Category;
use App\Services\Catalog\ActivityLogger;
use App\Services\Catalog\SearchTextBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CategoryController extends Controller
{
    public function __construct(private ActivityLogger $activity, private SearchTextBuilder $searchText) {}

    public function index(): JsonResponse
    {
        $categories = Category::withCount('products')->orderBy('display_order')->orderBy('name')->get();

        return response()->json(['data' => $categories->map(fn (Category $c) => $this->present($c))]);
    }

    public function store(CategoryRequest $request): JsonResponse
    {
        $category = DB::transaction(function () use ($request) {
            $category = new Category($request->validated());
            $category->slug = $category->slug ?: $this->uniqueSlug($category->name);
            $category->save();
            $this->activity->record($request->user(), 'created', $category);

            return $category;
        });

        return response()->json(['data' => $this->present($category->loadCount('products'))], 201);
    }

    public function update(CategoryRequest $request, Category $category): JsonResponse
    {
        DB::transaction(function () use ($request, $category) {
            $data = $request->validated();
            if (array_key_exists('slug', $data) && blank($data['slug'])) {
                unset($data['slug']);
            }
            $category->fill($data);
            $changed = array_keys($category->getDirty());
            $category->save();
            if (in_array('name', $changed, true)) {
                $this->searchText->refreshForCategory($category);
            }
            $this->activity->record($request->user(), 'updated', $category, $changed);
        });

        return response()->json(['data' => $this->present($category->loadCount('products'))]);
    }

    public function destroy(Category $category): Response
    {
        if ($category->products()->exists()) {
            throw ValidationException::withMessages([
                'category' => 'This category is used by products. Move them to another category or hide the category instead.',
            ]);
        }
        $this->activity->record(request()->user(), 'deleted', $category);
        $category->delete();

        return response()->noContent();
    }

    private function present(Category $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'status' => $category->status->value,
            'display_order' => $category->display_order,
            'product_count' => $category->products_count,
            'updated_at' => $category->updated_at?->toIso8601String(),
        ];
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        for ($i = 2; Category::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
