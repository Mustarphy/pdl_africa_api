<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\Account\AccountResource;
use App\Models\User;
use App\Services\Account\AccountMailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Customer sign-up and sign-in on the same Sanctum cookie session as the
 * admin SPA (no tokens reach JavaScript). Only `customer` accounts can use
 * these endpoints; administrators sign in through /admin.
 */
class AuthController extends Controller
{
    public function __construct(private readonly AccountMailer $mailer) {}

    public function register(Request $request): JsonResponse
    {
        if (Auth::guard('web')->check()) {
            return response()->json(['message' => 'You are already signed in.'], 409);
        }

        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'max:255', 'confirmed', Password::defaults()],
        ], [
            'email.unique' => 'An account with this email already exists. Sign in or reset your password.',
        ]);

        // Mass assignment is limited to name, email and password; the role
        // column defaults to `customer` and is never taken from input.
        $user = DB::transaction(fn () => User::create($data));

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        $sent = $this->mailer->verification($user);

        return (new AccountResource($user))
            ->additional(['meta' => ['verification_email_sent' => $sent]])
            ->response()
            ->setStatusCode(201);
    }

    public function login(Request $request): AccountResource
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        // The role condition is part of the lookup: an administrator's
        // credentials fail here exactly like a wrong password.
        $signedIn = Auth::guard('web')->attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
            'role' => UserRole::Customer->value,
        ], $request->boolean('remember'));

        if (! $signedIn) {
            throw ValidationException::withMessages([
                'email' => 'The email or password is incorrect.',
            ]);
        }

        $request->session()->regenerate();

        return new AccountResource($request->user());
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request): AccountResource
    {
        return new AccountResource($request->user());
    }
}
