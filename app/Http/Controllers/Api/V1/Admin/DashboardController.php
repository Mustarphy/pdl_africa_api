<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\CatalogStatus;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductCollection;
use App\Services\Catalog\PublicationRules;
use Illuminate\Http\JsonResponse;

/** Catalog health only: there are no orders/sales yet, so none are shown. */
class DashboardController extends Controller
{
    public function __invoke(PublicationRules $rules): JsonResponse
    {
        $countByStatus = fn (string $model) => collect(CatalogStatus::cases())
            ->mapWithKeys(fn ($s) => [$s->value => $model::where('status', $s)->count()])
            ->put('total', $model::count());

        $drafts = Product::where('status', CatalogStatus::Draft)
            ->with(['collections', 'variants', 'primaryImage'])->get();

        return response()->json(['data' => [
            'products' => $countByStatus(Product::class),
            'collections' => $countByStatus(ProductCollection::class),
            'categories' => ['total' => Category::count()],
            'products_without_images' => Product::whereDoesntHave('images')->where('status', '!=', CatalogStatus::Archived)->count(),
            'drafts_not_ready' => $drafts->filter(fn (Product $p) => $rules->productIssues($p) !== [])->count(),
            'recent_activity' => ActivityLog::with('user:id,name')->latest('created_at')->latest('id')->limit(12)->get()
                ->map(fn (ActivityLog $log) => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'subject_type' => $log->subject_type,
                    'subject_id' => $log->subject_id,
                    'subject_label' => $log->subject_label,
                    'changed_fields' => $log->changed_fields,
                    'user' => $log->user?->name,
                    'created_at' => $log->created_at->toIso8601String(),
                ]),
        ]]);
    }
}
