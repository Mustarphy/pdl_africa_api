<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Session (cookie) login for the admin SPA via Sanctum. The SPA first calls
 * GET /sanctum/csrf-cookie; every state-changing request then carries the
 * X-XSRF-TOKEN header. No tokens are exposed to JavaScript.
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $failed = ValidationException::withMessages([
            'email' => 'These credentials do not match an administrator account.',
        ]);

        if (! Auth::guard('web')->attempt($credentials)) {
            throw $failed;
        }
        if (! Auth::guard('web')->user()?->isAdmin()) {
            // Same message as a wrong password: do not reveal account details.
            Auth::guard('web')->logout();
            throw $failed;
        }

        $request->session()->regenerate();

        return response()->json(['data' => $this->present($request)]);
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($request)]);
    }

    private function present(Request $request): array
    {
        $user = $request->user();

        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role->value];
    }
}
