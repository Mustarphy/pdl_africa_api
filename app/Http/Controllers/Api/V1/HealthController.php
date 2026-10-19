<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    /**
     * Liveness check for the API. Deliberately reveals no environment,
     * version, dependency or configuration details.
     */
    public function __invoke(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }
}
