<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Http\Resources\Account\AccountResource;
use App\Services\Account\AccountMailer;
use App\Services\Account\SessionRevoker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/**
 * The signed-in customer's own profile. There is no user id in these routes:
 * every operation applies to $request->user(), so one customer can never
 * read or change another's account.
 */
class ProfileController extends Controller
{
    public function __construct(
        private readonly AccountMailer $mailer,
        private readonly SessionRevoker $sessions,
    ) {}

    public function update(Request $request): AccountResource
    {
        // Only the name is editable here; email has its own verified flow.
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
        ]);

        $user = $request->user();
        $user->update($data);

        return new AccountResource($user);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', 'max:255', 'confirmed', 'different:current_password', Password::defaults()],
        ], [
            'current_password.current_password' => 'Your current password is incorrect.',
            'password.different' => 'Choose a password different from your current one.',
        ]);

        $user = $request->user();
        $user->forceFill(['password' => $request->input('password')]);
        // Keeps this session (Sanctum stores the new password hash in it
        // after the response) and signs out every other device.
        $this->sessions->revoke($user, exceptSessionId: $request->session()->getId());
        $this->mailer->passwordChanged($user, viaReset: false);

        return response()->json(['message' => 'Your password has been changed.']);
    }
}
