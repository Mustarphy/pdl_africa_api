<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = Category::published()->orderBy('display_order')->orderBy('name')
            ->get(['slug', 'name', 'description']);

        return response()->json(['data' => $categories]);
    }
}
