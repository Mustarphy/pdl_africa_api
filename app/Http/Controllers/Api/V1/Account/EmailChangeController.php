<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\Account\AccountResource;
use App\Models\User;
use App\Services\Account\AccountLinks;
use App\Services\Account\AccountMailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Changing the account email: the new address is stored as `pending_email`
 * and only replaces `email` once its owner opens the signed link sent to it.
 * Until then the account keeps its current, verified address.
 */
class EmailChangeController extends Controller
{
    public function __construct(
        private readonly AccountLinks $links,
        private readonly AccountMailer $mailer,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $request->validate([
            'email' => [
                'required', 'string', 'email:rfc', 'max:255',
                Rule::notIn([$user->email]),
                Rule::unique('users', 'email'),
            ],
            'current_password' => ['required', 'string', 'current_password:web'],
        ], [
            'email.not_in' => 'This is already your email address.',
            'email.unique' => 'This email address cannot be used.',
            'current_password.current_password' => 'Your current password is incorrect.',
        ]);

        $user->forceFill(['pending_email' => $request->input('email')])->save();
        $sent = $this->mailer->emailChange($user);

        return (new AccountResource($user))
            ->additional(['meta' => ['confirmation_email_sent' => $sent]])
            ->response();
    }

    public function destroy(Request $request): AccountResource
    {
        $user = $request->user();
        $user->forceFill(['pending_email' => null])->save();

        return new AccountResource($user);
    }

    /** Opened from the link emailed to the new address (via the storefront). */
    public function confirm(Request $request, string $id, string $hash): JsonResponse
    {
        $check = $this->links->check($request);
        $user = $check === AccountLinks::INVALID ? null
            : User::where('role', UserRole::Customer->value)->find($id);

        // A missing pending address means the link was used, cancelled or
        // replaced by a newer request.
        if (! $user || $user->pending_email === null || ! hash_equals(sha1($user->pending_email), $hash)) {
            return response()->json(['message' => 'This link is invalid or has already been used.', 'reason' => 'invalid'], 403);
        }
        if ($check === AccountLinks::EXPIRED) {
            return response()->json(['message' => 'This link has expired.', 'reason' => 'expired'], 410);
        }

        $previous = $user->email;
        $changed = DB::transaction(function () use ($user, $previous) {
            // Re-check under a lock: the address may have been registered
            // since the change was requested.
            if (User::whereKeyNot($user->getKey())->where('email', $user->pending_email)->lockForUpdate()->exists()) {
                $user->forceFill(['pending_email' => null])->save();

                return false;
            }
            $user->forceFill([
                'email' => $user->pending_email,
                'pending_email' => null,
                'email_verified_at' => now(),
            ])->save();
            // Reset links sent to the old address must stop working.
            DB::table(config('auth.passwords.users.table'))->where('email', $previous)->delete();

            return true;
        });

        if (! $changed) {
            return response()->json(['message' => 'This email address is no longer available.', 'reason' => 'unavailable'], 409);
        }

        $this->mailer->emailChanged($user, $previous);

        return response()->json(['data' => ['status' => 'changed']]);
    }
}
