<?php

use App\Http\Controllers\Api\V1\Admin;
use App\Http\Controllers\Api\V1\Catalog;
use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

/*
| Versioned JSON API consumed by the React frontend. Laravel adds the `/api`
| prefix (see bootstrap/app.php), so these routes live under `/api/v1`.
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::get('health', HealthController::class)->name('health');

    // Public catalog: published records only. Short shared caching keeps
    // newly published products visible within a minute.
    Route::middleware('cache.headers:public;max_age=60;etag')->group(function () {
        Route::get('collections', [Catalog\CollectionController::class, 'index'])->name('collections.index');
        Route::get('collections/{slug}', [Catalog\CollectionController::class, 'show'])->name('collections.show');
        Route::get('categories', [Catalog\CategoryController::class, 'index'])->name('categories.index');
        Route::get('products', [Catalog\ProductController::class, 'index'])->name('products.index');
        Route::get('products/{slug}', [Catalog\ProductController::class, 'show'])->name('products.show');
        Route::get('search/suggestions', Catalog\SearchSuggestionController::class)->name('search.suggestions');
        Route::get('catalog/legacy-map', Catalog\LegacyMapController::class)->name('catalog.legacy-map');
    });

    // Admin (Sanctum cookie session). Never cached by browsers or proxies.
    Route::prefix('admin')->name('admin.')->middleware('cache.headers:no_store;private')->group(function () {
        Route::post('login', [Admin\AuthController::class, 'login'])->middleware('throttle:admin-login')->name('login');

        Route::middleware(['auth:sanctum', 'can:manage-catalog'])->group(function () {
            Route::post('logout', [Admin\AuthController::class, 'logout'])->name('logout');
            Route::get('me', [Admin\AuthController::class, 'me'])->name('me');
            Route::get('dashboard', Admin\DashboardController::class)->name('dashboard');

            Route::apiResource('categories', Admin\CategoryController::class)->except('show');

            Route::apiResource('collections', Admin\CollectionController::class);
            Route::post('collections/{collection}/status', [Admin\CollectionController::class, 'status'])->name('collections.status');
            Route::post('collections/{collection}/images/{slot}', [Admin\CollectionController::class, 'uploadImage'])
                ->whereIn('slot', ['cover', 'banner'])->name('collections.images.store');
            Route::delete('collections/{collection}/images/{slot}', [Admin\CollectionController::class, 'removeImage'])
                ->whereIn('slot', ['cover', 'banner'])->name('collections.images.destroy');

            Route::apiResource('products', Admin\ProductController::class);
            Route::post('products/{product}/status', [Admin\ProductController::class, 'status'])->name('products.status');
            Route::scopeBindings()->group(function () {
                Route::post('products/{product}/images', [Admin\ProductImageController::class, 'store'])->name('products.images.store');
                Route::put('products/{product}/images/order', [Admin\ProductImageController::class, 'reorder'])->name('products.images.reorder');
                Route::patch('products/{product}/images/{image}', [Admin\ProductImageController::class, 'update'])->name('products.images.update');
                Route::delete('products/{product}/images/{image}', [Admin\ProductImageController::class, 'destroy'])->name('products.images.destroy');
            });
        });
    });
});
