<?php

use App\Http\Controllers\Api\V1\Account;
use App\Http\Controllers\Api\V1\Admin;
use App\Http\Controllers\Api\V1\Catalog;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PaystackWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Versioned JSON API consumed by the React frontend. Laravel adds the `/api`
| prefix (see bootstrap/app.php), so these routes live under `/api/v1`.
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::get('health', HealthController::class)->name('health');

    // Checkout and orders: session-based (storefront proxy), never cached.
    // Prices, totals and payment state are decided here, not by the client.
    Route::middleware('cache.headers:no_store;private')->group(function () {
        Route::post('checkout/quote', [CheckoutController::class, 'quote'])->middleware('throttle:checkout-quote')->name('checkout.quote');
        Route::post('checkout', [CheckoutController::class, 'store'])->middleware('throttle:checkout')->name('checkout.store');

        Route::post('orders/lookup', [OrderController::class, 'lookup'])->middleware('throttle:order-lookup')->name('orders.lookup');
        Route::get('orders/{reference}', [OrderController::class, 'show'])->name('orders.show');
        Route::post('orders/{reference}/pay', [OrderController::class, 'pay'])->middleware('throttle:checkout')->name('orders.pay');
        // Signed link from an order email (signature checked in the controller).
        Route::post('orders/{reference}/access', [OrderController::class, 'access'])->middleware('throttle:account-links')->name('orders.access');
    });

    // Paystack -> API. Authenticated by the HMAC signature, not a session.
    Route::post('payments/paystack/webhook', PaystackWebhookController::class)->name('payments.paystack.webhook');

    Route::post('contact', [ContactController::class, 'send'])
        ->middleware('throttle:10,1')
        ->name('contact.send');

    // Public catalog: published records only. Short shared caching keeps
    // newly published products visible within a minute.
    Route::middleware('cache.headers:public;max_age=60;etag')->group(function () {
        Route::get('collections', [Catalog\CollectionController::class, 'index'])->name('collections.index');
        Route::get('collections/{slug}', [Catalog\CollectionController::class, 'show'])->name('collections.show');
        Route::get('categories', [Catalog\CategoryController::class, 'index'])->name('categories.index');
        Route::get('occasions', [Catalog\OccasionController::class, 'index'])->name('occasions.index');
        Route::get('occasions/{slug}', [Catalog\OccasionController::class, 'show'])->name('occasions.show');
        Route::get('products', [Catalog\ProductController::class, 'index'])->name('products.index');
        Route::get('products/{slug}', [Catalog\ProductController::class, 'show'])->name('products.show');
        Route::get('search/suggestions', Catalog\SearchSuggestionController::class)->name('search.suggestions');
        Route::get('catalog/legacy-map', Catalog\LegacyMapController::class)->name('catalog.legacy-map');
    });

    // Customer accounts (the same Sanctum cookie session as the admin, via
    // the storefront's proxy). Never cached by browsers or proxies.
    Route::prefix('account')->name('account.')->middleware('cache.headers:no_store;private')->group(function () {
        Route::post('register', [Account\AuthController::class, 'register'])->middleware('throttle:account-register')->name('register');
        Route::post('login', [Account\AuthController::class, 'login'])->middleware('throttle:account-login')->name('login');
        Route::post('forgot-password', [Account\PasswordResetController::class, 'sendLink'])->middleware('throttle:account-password-email')->name('password.email');
        Route::post('reset-password', [Account\PasswordResetController::class, 'reset'])->middleware('throttle:account-links')->name('password.reset');

        // Signed links from account emails (signature checked in the controller).
        Route::post('email/verify/{id}/{hash}', [Account\EmailVerificationController::class, 'verify'])
            ->middleware('throttle:account-links')->name('verification.verify');
        Route::post('email/confirm/{id}/{hash}', [Account\EmailChangeController::class, 'confirm'])
            ->middleware('throttle:account-links')->name('email.confirm');

        Route::middleware(['auth:sanctum', 'can:use-customer-account'])->group(function () {
            Route::get('me', [Account\AuthController::class, 'me'])->name('me');
            Route::post('logout', [Account\AuthController::class, 'logout'])->name('logout');
            Route::get('orders', [Account\OrderController::class, 'index'])->name('orders.index');
            Route::post('email/verification-notification', [Account\EmailVerificationController::class, 'resend'])
                ->middleware('throttle:account-verification')->name('verification.send');

            // Changes require a verified address.
            Route::middleware('verified')->group(function () {
                Route::patch('profile', [Account\ProfileController::class, 'update'])->name('profile.update');
                Route::put('password', [Account\ProfileController::class, 'updatePassword'])
                    ->middleware('throttle:account-sensitive')->name('password.update');
                Route::post('email', [Account\EmailChangeController::class, 'store'])
                    ->middleware('throttle:account-sensitive')->name('email.store');
                Route::delete('email', [Account\EmailChangeController::class, 'destroy'])->name('email.destroy');
            });
        });
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

            Route::apiResource('occasions', Admin\OccasionController::class);
            Route::post('occasions/{occasion}/status', [Admin\OccasionController::class, 'status'])->name('occasions.status');
            Route::put('occasions/{occasion}/products', [Admin\OccasionController::class, 'products'])->name('occasions.products');
            Route::post('occasions/{occasion}/image', [Admin\OccasionController::class, 'uploadImage'])->name('occasions.image.store');
            Route::delete('occasions/{occasion}/image', [Admin\OccasionController::class, 'removeImage'])->name('occasions.image.destroy');

            Route::get('orders', [Admin\OrderController::class, 'index'])->name('orders.index');
            Route::get('orders/{order:reference}', [Admin\OrderController::class, 'show'])->name('orders.show');
            Route::post('orders/{order:reference}/status', [Admin\OrderController::class, 'status'])->name('orders.status');
            Route::put('orders/{order:reference}/tracking', [Admin\OrderController::class, 'tracking'])->name('orders.tracking');
            Route::put('orders/{order:reference}/notes', [Admin\OrderController::class, 'notes'])->name('orders.notes');
            Route::post('orders/{order:reference}/verify-payment', [Admin\OrderController::class, 'verifyPayment'])
                ->middleware('throttle:10,1')->name('orders.verify-payment');

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
