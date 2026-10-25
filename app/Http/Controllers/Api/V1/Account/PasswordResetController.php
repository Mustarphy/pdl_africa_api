<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Account\AccountMailer;
use App\Services\Account\SessionRevoker;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * Forgotten passwords, using Laravel's password broker: hashed single-use
 * tokens in password_reset_tokens, expiring after auth.passwords.users.expire
 * minutes, at most one email per address per `throttle` seconds.
 */
class PasswordResetController extends Controller
{
    public function __construct(
        private readonly AccountMailer $mailer,
        private readonly SessionRevoker $sessions,
    ) {}

    /** Same response whether or not the address has an account. */
    public function sendLink(Request $request): JsonResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);

        // Unknown, administrator and throttled addresses are all silently
        // skipped. The emailed link is built by User::sendPasswordResetNotification.
        Password::broker()->sendResetLink([
            'email' => $request->input('email'),
            'role' => UserRole::Customer->value,
        ]);

        return response()->json([
            'message' => 'If an account exists for that email, we have sent a link to reset the password.',
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::broker()->reset([
            'email' => $request->input('email'),
            'role' => UserRole::Customer->value,
            'password' => $request->input('password'),
            'token' => $request->input('token'),
        ], function (User $user, string $password) {
            $user->forceFill(['password' => $password]);
            // Saves the user and signs out every device (including any
            // session an attacker may hold).
            $this->sessions->revoke($user);
            event(new PasswordReset($user));
            $this->mailer->passwordChanged($user, viaReset: true);
        });

        if ($status !== Password::PASSWORD_RESET) {
            // Invalid, expired, already used, or not a customer account.
            throw ValidationException::withMessages([
                'token' => 'This password reset link is invalid or has expired. Please request a new one.',
            ]);
        }

        return response()->json(['message' => 'Your password has been reset. You can now sign in.']);
    }
}
