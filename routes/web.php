<?php

use Illuminate\Support\Facades\Route;

// API-only backend: the storefront is the separate React application.
Route::get('/', fn () => response()->json([
    'name' => config('app.name').' API',
    'api' => url('/api/v1'),
]));
